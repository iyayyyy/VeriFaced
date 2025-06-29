<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php'; // Ensure this file creates a $conn mysqli object

function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if added member is logged in
if (!isset($_SESSION['added_member_logged_in']) || $_SESSION['added_member_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in as a member to view your dashboard.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: memberlogin.php"); // Redirect to member login page
    exit();
}

$logged_in_added_member_id = $_SESSION['added_member_id'];
$logged_in_added_member_tenant_id = $_SESSION['added_member_tenant_id']; // The tenant this member belongs to

// Initialize member data
$member_full_name = '';
$member_username = '';
$member_phone_number = '';
$member_relationship = '';
$member_profile_pic_path = 'https://placehold.co/150x150/A0A0A0/FFFFFF?text=Member+Image'; // Default placeholder
// NEW: Initialize for current status and last status update
$member_current_status = 'N/A';
$member_last_status_update = 'N/A';


// Initialize variables for the new accepted visitors section
$accepted_visitors_for_me_html = '';

// Flash Message handling
$flash_message = '';
$flash_message_type = '';
if (isset($_SESSION['flash_message'])) {
    $flash_message = e($_SESSION['flash_message']);
    $flash_message_type = e($_SESSION['flash_message_type'] ?? 'info');
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Handle form submission for updating member profile
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_member_profile') {
    $new_full_name = trim($_POST['full_name'] ?? '');
    $new_phone_number = trim($_POST['phone_number'] ?? '');
    $new_username = trim($_POST['username'] ?? ''); // Get the new username

    $update_errors = [];

    if (empty($new_full_name)) $update_errors[] = "Full Name cannot be empty.";
    if (empty($new_phone_number)) $update_errors[] = "Phone Number cannot be empty.";
    if (empty($new_username)) $update_errors[] = "Username cannot be empty."; // Validate username

    $new_known_face_path_2 = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_image']['tmp_name'];
        $fileName = $_FILES['profile_image']['name'];
        $fileSize = $_FILES['profile_image']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $uploadDir = './uploads/member_profile_pics/'; // New directory for members
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $newFileName = uniqid('member_') . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $new_known_face_path_2 = $destPath;
            } else {
                $update_errors[] = "Error uploading profile image.";
            }
        } else {
            $update_errors[] = "Invalid image format. Only JPG, JPEG, PNG, GIF allowed.";
        }
    }

    if (empty($update_errors)) {
        if (isset($conn) && $conn instanceof mysqli) {
            // Include username in the update query
            $update_sql = "UPDATE addedmembers SET full_name = ?, username = ?, phone_number = ?";
            if ($new_known_face_path_2) {
                $update_sql .= ", known_face_path_2 = ?";
            }
            $update_sql .= " WHERE added_member_id = ?";

            $stmt_update = $conn->prepare($update_sql);
            if ($stmt_update) {
                if ($new_known_face_path_2) {
                    // Bind new_username here
                    $stmt_update->bind_param("ssssi", $new_full_name, $new_username, $new_phone_number, $new_known_face_path_2, $logged_in_added_member_id);
                } else {
                    // Bind new_username here
                    $stmt_update->bind_param("sssi", $new_full_name, $new_username, $new_phone_number, $logged_in_added_member_id);
                }

                if ($stmt_update->execute()) {
                    $_SESSION['flash_message'] = "Profile updated successfully!";
                    $_SESSION['flash_message_type'] = "success";
                } else {
                    // Check for duplicate username error
                    if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                         $_SESSION['flash_message'] = "Error updating profile: The username '{$new_username}' is already taken.";
                    } else {
                         $_SESSION['flash_message'] = "Error updating profile: " . $stmt_update->error;
                    }
                    $_SESSION['flash_message_type'] = "danger";
                    error_log("Error updating added member profile: " . $stmt_update->error);
                }
                $stmt_update->close();
            } else {
                $_SESSION['flash_message'] = "Database error: Could not prepare update statement.";
                $_SESSION['flash_message_type'] = "danger";
                error_log("Failed to prepare update statement for M_Dashboard: " . $conn->error);
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Unable to update profile.";
            $_SESSION['flash_message_type'] = "danger";
        }
    } else {
        $_SESSION['flash_message'] = implode("<br>", $update_errors);
        $_SESSION['flash_message_type'] = "danger";
    }
    header("Location: M_Dashboard.php"); // PRG pattern
    exit();
}

// Fetch Added Member Data for display
if (isset($conn) && $conn instanceof mysqli) {
    $stmt_member_data = null;
    try {
        // MODIFIED: Added current_status and last_status_update
        $sql_member_data = "SELECT full_name, username, phone_number, relationship_to_tenant, known_face_path_2, current_status, last_status_update
                            FROM addedmembers
                            WHERE added_member_id = ? LIMIT 1";
        $stmt_member_data = $conn->prepare($sql_member_data);
        if ($stmt_member_data) {
            $stmt_member_data->bind_param("i", $logged_in_added_member_id);
            $stmt_member_data->execute();
            $result_member_data = $stmt_member_data->get_result();

            if ($result_member_data->num_rows === 1) {
                $member_data = $result_member_data->fetch_assoc();
                $member_full_name = e($member_data['full_name']);
                $member_username = e($member_data['username']);
                $member_phone_number = e($member_data['phone_number']);
                $member_relationship = e($member_data['relationship_to_tenant']);
                
                $member_profile_pic_path_fs = !empty($member_data['known_face_path_2']) ? realpath($member_data['known_face_path_2']) : false;
                $member_profile_pic_path = ($member_profile_pic_path_fs && file_exists($member_profile_pic_path_fs)) ? e($member_data['known_face_path_2']) : 'https://placehold.co/150x150/A0A0A0/FFFFFF?text=Member+Image';
                
                // NEW: Fetch and format status data
                $member_current_status = e($member_data['current_status'] ?? 'N/A');
                if (!empty($member_data['last_status_update'])) {
                    $dt = new DateTime($member_data['last_status_update']);
                    $member_last_status_update = e($dt->format('M d, Y h:i A'));
                } else {
                    $member_last_status_update = 'Never updated';
                }

            } else {
                $_SESSION['flash_message'] = "Member profile not found.";
                $_SESSION['flash_message_type'] = "danger";
                header("Location: memberlogin.php");
                exit();
            }
        } else {
            error_log("Failed to prepare member data statement for M_Dashboard: " . $conn->error);
        }
    } catch (Throwable $e) {
        error_log("Error fetching member data for M_Dashboard: " . $e->getMessage());
    } finally {
        if ($stmt_member_data) {
            $stmt_member_data->close();
        }
    }

    // --- UPDATED: Fetch Accepted Visitors for the Logged-in Registered Member ---
    $stmt_accepted_visitors = null;
    try {
        $sql_accepted_visitors = "SELECT v.visitor_id, v.full_name, u.unit_number, v.phone_number, v.purpose_of_visit, 
                                          v.relationship_to_tenant, v.profile_pic_path, v.visit_timestamp
                                   FROM visitors v
                                   JOIN units u ON v.unit_id = u.unit_id
                                   WHERE v.status = 'accepted' 
                                     AND v.specific_host_type = 'member'
                                     AND v.specific_host_id = ?
                                   ORDER BY v.visit_timestamp DESC";
        
        $stmt_accepted_visitors = $conn->prepare($sql_accepted_visitors);
        if ($stmt_accepted_visitors) {
            $stmt_accepted_visitors->bind_param("i", $logged_in_added_member_id);
            $stmt_accepted_visitors->execute();
            $result_accepted_visitors = $stmt_accepted_visitors->get_result();

            // Start building the HTML with the new structure
            $accepted_visitors_for_me_html = "
                <div class='visitors-container-header'>
                    <h3>Accepted Visitors For Me</h3>
                    <p>These visitors have been accepted and designated you as their host.</p>
                </div>
            ";

            if ($result_accepted_visitors->num_rows > 0) {
                $accepted_visitors_for_me_html .= "<div class='visitors-list-wrapper'>";
                
                while ($visitor = $result_accepted_visitors->fetch_assoc()) {
                    $visitor_photo_src = !empty($visitor['profile_pic_path']) && file_exists($visitor['profile_pic_path']) ? e($visitor['profile_pic_path']) : 'https://placehold.co/64x64/8B864E/FFFFFF?text=No+Photo';
                    
                    $submission_time = new DateTime($visitor['visit_timestamp']);
                    $formatted_submission_time = e($submission_time->format('M d, Y h:i A'));
                    
                    $accepted_visitors_for_me_html .= "
                        <div class='member-visitor-list-item' data-visitor-id='" . e($visitor['visitor_id']) . "'>
                            <div class='item-left'>
                                <div class='item-avatar'>
                                    <img src='{$visitor_photo_src}' alt='Visitor Photo' onerror=\"this.onerror=null;this.src='https://placehold.co/64x64/8B864E/FFFFFF?text=No+Photo';\">
                                </div>
                                <div class='item-details'>
                                    <p><strong>Name:</strong> " . e($visitor['full_name']) . "</p>
                                    <p><strong>Phone:</strong> " . e($visitor['phone_number']) . "</p>
                                    <p><strong>Purpose:</strong> " . e($visitor['purpose_of_visit']) . "</p>
                                    <p><strong>Relationship:</strong> " . e($visitor['relationship_to_tenant']) . "</p>
                                    <p style='grid-column: 1 / -1;'><strong>Processed:</strong> " . $formatted_submission_time . "</p>
                                </div>
                            </div>
                            <div class='item-actions-remove'>
                                <button type='button' class='btn-remove' data-visitor-id='" . e($visitor['visitor_id']) . "'>Remove</button>
                            </div>
                        </div>
                    ";
                }
                
                $accepted_visitors_for_me_html .= "</div>"; // Close visitors-list-wrapper
            } else {
                $accepted_visitors_for_me_html .= "
                    <div class='empty-state'>
                        <h3>No Accepted Visitors</h3>
                        <p>No visitors have been accepted to see you yet. When visitors are approved, they will appear here.</p>
                    </div>
                ";
            }
        } else {
            error_log("Failed to prepare statement for Accepted Visitors for Member: " . $conn->error);
            $accepted_visitors_for_me_html = "
                <div class='visitors-container-header'>
                    <h3>Accepted Visitors For Me</h3>
                    <p>These visitors have been accepted and designated you as their host.</p>
                </div>
                <div class='empty-state'>
                    <div class='empty-state-icon'>⚠️</div>
                    <h3>Error loading visitors</h3>
                    <p>Database statement preparation failed.</p>
                </div>
            ";
        }
    } catch (Throwable $e) {
        error_log("Error fetching accepted visitors for logged-in member: " . $e->getMessage());
        $accepted_visitors_for_me_html = "
            <div class='visitors-container-header'>
                <h3>Accepted Visitors For Me</h3>
                <p>These visitors have been accepted and designated you as their host.</p>
            </div>
            <div class='empty-state'>
                <div class='empty-state-icon'>⚠️</div>
                <h3>An unexpected error occurred</h3>
                <p>Please try again later.</p>
            </div>
        ";
    } finally {
        if ($stmt_accepted_visitors) {
            $stmt_accepted_visitors->close();
        }
    }
    // --- END UPDATED SECTION ---

} else {
    error_log("Database connection not available in M_Dashboard.php (GET).");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verifaced: Member Dashboard</title>
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
      display: flex;
      min-height: 100vh;
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
      position: fixed;
      top: 0;
      left: 0;
      height: 100vh;
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
      box-shadow: 0 2px 5px rgba(0,0,0,0.05);
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
      margin-left: 240px;
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

    /* PROFILE CARD */
    .profile-card {
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

    .profile-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }

    .profile-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .profile-header-section {
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

    .profile-details-grid {
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

    /* IMPROVED ACCEPTED VISITORS CONTAINER */
    #acceptedVisitorsForMeContainer {
      background: linear-gradient(135deg, var(--white) 0%, var(--off-white) 100%);
      border-radius: var(--radius-xl);
      padding: 0;
      box-shadow: var(--shadow-lg);
      border: 1px solid var(--gray-200);
      margin-top: 24px;
      overflow: hidden;
      position: relative;
      backdrop-filter: blur(20px);
    }

    /* Add decorative top border */
    #acceptedVisitorsForMeContainer::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, 
          var(--khaki-primary) 0%, 
          var(--khaki-secondary) 50%, 
          var(--khaki-dark) 100%);
    }

    /* Container header styling */
    .visitors-container-header {
      padding: 24px 28px 20px;
      background: linear-gradient(135deg, 
          rgba(189, 183, 107, 0.05) 0%, 
          rgba(240, 230, 140, 0.03) 100%);
      border-bottom: 1px solid var(--gray-200);
      text-align: center;
    }

    .visitors-container-header h3 {
      color: var(--gray-800);
      font-size: 20px;
      font-weight: 700;
      margin: 0 0 6px 0;
      letter-spacing: -0.3px;
    }

    .visitors-container-header p {
      color: var(--gray-600);
      font-size: 14px;
      margin: 0;
      opacity: 0.9;
    }

    /* Visitors list container */
    .visitors-list-wrapper {
      padding: 20px 0;
      max-height: 500px;
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: var(--khaki-primary) var(--gray-200);
    }

    .visitors-list-wrapper::-webkit-scrollbar {
      width: 6px;
    }

    .visitors-list-wrapper::-webkit-scrollbar-track {
      background: var(--gray-200);
      border-radius: 3px;
    }

    .visitors-list-wrapper::-webkit-scrollbar-thumb {
      background: var(--khaki-primary);
      border-radius: 3px;
    }

    .visitors-list-wrapper::-webkit-scrollbar-thumb:hover {
      background: var(--khaki-dark);
    }

    /* Individual visitor card styling */
    .member-visitor-list-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 20px 28px;
      margin: 0 20px 16px;
      background: var(--white);
      border-radius: var(--radius-lg);
      border: 1px solid var(--gray-200);
      box-shadow: var(--shadow-sm);
      transition: var(--transition-normal);
      position: relative;
      overflow: hidden;
    }

    .member-visitor-list-item:last-child {
      margin-bottom: 0;
    }

    .member-visitor-list-item::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 4px;
      background: linear-gradient(180deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      transform: scaleY(0);
      transition: transform var(--transition-normal);
    }

    .member-visitor-list-item:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
      border-color: var(--khaki-primary);
    }

    .member-visitor-list-item:hover::before {
      transform: scaleY(1);
    }

    /* Left section with photo and details */
    .member-visitor-list-item .item-left {
      display: flex;
      align-items: center;
      gap: 20px;
      flex-grow: 1;
    }

    /* Visitor avatar styling */
    .member-visitor-list-item .item-avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      overflow: hidden;
      flex-shrink: 0;
      border: 3px solid var(--white);
      box-shadow: var(--shadow-md);
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      transition: var(--transition-normal);
    }

    .member-visitor-list-item .item-avatar::after {
      content: '';
      position: absolute;
      inset: -3px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--khaki-primary), var(--khaki-dark));
      z-index: -1;
      opacity: 0;
      transition: opacity var(--transition-normal);
    }

    .member-visitor-list-item:hover .item-avatar::after {
      opacity: 1;
    }

    .member-visitor-list-item .item-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform var(--transition-normal);
    }

    .member-visitor-list-item:hover .item-avatar img {
      transform: scale(1.05);
    }

    /* Visitor details styling */
    .member-visitor-list-item .item-details {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 24px;
      flex-grow: 1;
    }

    .member-visitor-list-item .item-details p {
      margin: 0;
      line-height: 1.4;
      font-size: 14px;
      color: var(--gray-700);
      display: flex;
      align-items: center;
    }

    .member-visitor-list-item .item-details p strong {
      font-weight: 600;
      color: var(--gray-800);
      margin-right: 8px;
      min-width: 80px;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .member-visitor-list-item .item-details p:first-child strong {
      color: var(--khaki-dark);
    }

    /* Action button styling */
    .member-visitor-list-item .item-actions-remove {
      flex-shrink: 0;
      margin-left: 20px;
    }

    .member-visitor-list-item .btn-remove {
      background: linear-gradient(135deg, var(--action-reject) 0%, var(--action-reject-dark) 100%);
      color: var(--white);
      padding: 10px 20px;
      border-radius: var(--radius-md);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-normal);
      border: none;
      box-shadow: var(--shadow-sm);
      font-family: inherit;
      position: relative;
      overflow: hidden;
      min-width: 90px;
    }

    .member-visitor-list-item .btn-remove::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }

    .member-visitor-list-item .btn-remove:hover {
      background: linear-gradient(135deg, var(--action-reject-dark) 0%, #B22222 100%);
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }

    .member-visitor-list-item .btn-remove:active {
      transform: translateY(0);
    }

    /* Enhanced empty state */
    #acceptedVisitorsForMeContainer .empty-state {
      text-align: center;
      padding: 60px 40px;
      color: var(--gray-600);
      background: none;
      border: none;
      box-shadow: none;
    }

    .empty-state-icon {
      width: 80px;
      height: 80px;
      margin: 0 auto 20px;
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
      color: var(--white);
      box-shadow: var(--shadow-md);
    }

    #acceptedVisitorsForMeContainer .empty-state h3 {
      font-size: 18px;
      margin-bottom: 12px;
      color: var(--gray-700);
      font-weight: 600;
    }

    #acceptedVisitorsForMeContainer .empty-state p {
      font-size: 14px;
      opacity: 0.8;
      line-height: 1.6;
      max-width: 300px;
      margin: 0 auto;
    }

    /* SUCCESS/FLASH MESSAGES */
    .success-message {
      display: none;
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
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
        margin-left: 200px;
      }

      .profile-details-grid {
        grid-template-columns: 1fr;
        gap: 16px;
      }
      .profile-card {
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
        position: static;
        height: auto;
      }

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
      }

      .content-header h1 {
        font-size: 24px;
      }

      .profile-header-section {
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

      /* Enhanced mobile responsive for visitors container */
      #acceptedVisitorsForMeContainer {
        margin: 16px 0;
        border-radius: var(--radius-lg);
      }

      .visitors-container-header {
        padding: 20px 20px 16px;
      }

      .visitors-container-header h3 {
        font-size: 18px;
      }

      .member-visitor-list-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
        padding: 20px;
        margin: 0 16px 12px;
      }

      .member-visitor-list-item .item-left {
        width: 100%;
        gap: 16px;
      }

      .member-visitor-list-item .item-avatar {
        width: 56px;
        height: 56px;
      }

      .member-visitor-list-item .item-details {
        grid-template-columns: 1fr;
        gap: 6px;
        width: 100%;
      }

      .member-visitor-list-item .item-details p strong {
        min-width: 70px;
        font-size: 11px;
      }

      .member-visitor-list-item .item-actions-remove {
        width: 100%;
        margin-left: 0;
        text-align: center;
      }

      .member-visitor-list-item .btn-remove {
        width: 100%;
        padding: 12px;
      }

      #acceptedVisitorsForMeContainer .empty-state {
        padding: 40px 20px;
      }

      .empty-state-icon {
        width: 60px;
        height: 60px;
        font-size: 24px;
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
      .profile-card {
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

      .visitors-container-header {
        padding: 16px;
      }

      .member-visitor-list-item {
        padding: 16px;
        margin: 0 12px 10px;
      }

      .member-visitor-list-item .item-avatar {
        width: 48px;
        height: 48px;
      }

      .member-visitor-list-item .item-details p {
        font-size: 13px;
      }

      .member-visitor-list-item .item-details p strong {
        font-size: 10px;
        min-width: 60px;
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

    @keyframes slideInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .profile-card, .member-visitor-list-item {
      animation: fadeInUp 0.6s ease-out;
    }

    .member-visitor-list-item:nth-child(even) {
      animation-delay: 0.05s;
    }

    /* FOCUS STYLES FOR ACCESSIBILITY */
    .btn:focus,
    .detail-input:focus,
    .sidebar nav li a:focus,
    .action-buttons .btn:focus,
    .modal-buttons button:focus,
    .logout:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES */
    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* PRINT STYLES */
    @media print {
      .sidebar,
      .action-buttons,
      .modal,
      .success-message,
      .profile-image-container button {
        display: none;
      }

      .main-content {
        padding: 0;
      }

      .profile-card,
      .member-visitor-list-item {
        box-shadow: none;
        border: 1px solid var(--gray-300);
        break-inside: avoid;
        margin-bottom: 20px;
      }
      .profile-header-section {
        flex-direction: row;
        align-items: flex-start;
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
          <li><a href="M_Dashboard.php"><span></span> My Profile</a></li>
          <li><a href="M_ChangePassword.php"><span></span> Change Password</a></li>
        </ul>
      </nav>
      <a href="#" class="logout" id="logoutLink">Log out</a>
    </aside>

    <main class="main-content">
      <div class="content-header">
        <h1>Member Dashboard</h1>
        <p>Manage your personal profile and activity</p>
      </div>

      <?php if ($flash_message): ?>
          <div id="phpFlashMessage" class='success-message <?php echo ($flash_message ? "show" : ""); ?>' style="background: <?php echo ($flash_message_type === 'success' ? 'linear-gradient(135deg, var(--success) 0%, #218838 100%)' : 'linear-gradient(135deg, var(--danger) 0%, #C82333 100%)'); ?>; color: white; border-color: <?php echo ($flash_message_type === 'success' ? '#218838' : '#C82333'); ?>;">
            <span><?php echo $flash_message; ?></span>
          </div>
      <?php endif; ?>

      <form id="memberProfileForm" method="POST" action="M_Dashboard.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_member_profile">
        <div class="profile-card">
          <div class="profile-header-section">
            <div class="profile-image-container">
              <div class="profile-image">
                <img src="<?php echo $member_profile_pic_path; ?>" alt="Member Photo" id="memberImagePreview" onerror="this.onerror=null;this.src='https://placehold.co/150x150/A0A0A0/FFFFFF?text=Member+Image';">
              </div>
              <input type="file" id="profileImageUpload" name="profile_image" accept="image/*" style="display: none;">
            </div>

            <div class="profile-details-grid">
              <div class="detail-group">
                <label class="detail-label">Full Name</label>
                <input type="text" class="detail-input" name="full_name" placeholder="Enter your full name" value="<?php echo $member_full_name; ?>" required>
              </div>

              <div class="detail-group">
                <label class="detail-label">Username</label>
                <input type="text" class="detail-input" name="username" placeholder="Your username" value="<?php echo $member_username; ?>">
              </div>

              <div class="detail-group">
                <label class="detail-label">Phone Number</label>
                <input type="tel" class="detail-input" name="phone_number" placeholder="Enter your phone number" value="<?php echo $member_phone_number; ?>" required>
              </div>

              <div class="detail-group">
                <label class="detail-label">Relationship to Tenant</label>
                <input type="text" class="detail-input" name="relationship_to_tenant" placeholder="e.g., Son, Daughter, Spouse" value="<?php echo $member_relationship; ?>" readonly>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Current Status</label>
                <input type="text" class="detail-input" name="current_status" value="<?php echo $member_current_status; ?>" readonly>
              </div>
              <div class="detail-group">
                <label class="detail-label">Last Status Update</label>
                <input type="text" class="detail-input" name="last_status_update" value="<?php echo $member_last_status_update; ?>" readonly>
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
      
      <div id="acceptedVisitorsForMeContainer">
        <?php echo $accepted_visitors_for_me_html; ?>
      </div>
      </main>
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

  <div id="successMessage" class="success-message">
    <span></span>
  </div>
  <script>
    // Get references to DOM elements
    const logoutLink = document.getElementById('logoutLink');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogoutBtn = document.getElementById('confirmLogout');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    const successMessage = document.getElementById('successMessage');

    // Generic Confirmation Modal Elements
    const genericConfirmModal = document.getElementById('genericConfirmModal');
    const genericConfirmModalTitle = document.getElementById('genericConfirmModalTitle');
    const genericConfirmModalMessage = document.getElementById('genericConfirmModalMessage');
    const genericConfirmBtn = document.getElementById('genericConfirmBtn');
    const genericCancelBtn = document.getElementById('genericCancelBtn');
    let currentConfirmAction = null;

    // Change Password Button (on main profile card)
    const changePasswordBtn = document.getElementById('changePasswordBtn');

    // Check In/Out Buttons (main profile card)
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');

    // Member ID for AJAX calls
    const currentMemberId = <?php echo json_encode($logged_in_added_member_id); ?>;

    // Profile Image Upload
    const profileImageUpload = document.getElementById('profileImageUpload');
    const memberImagePreview = document.getElementById('memberImagePreview');

    memberImagePreview.addEventListener('click', function() {
        profileImageUpload.click();
    });

    profileImageUpload.addEventListener('change', function(event) {
        if (event.target.files && event.target.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                memberImagePreview.src = e.target.result;
            };
            reader.readAsDataURL(event.target.files[0]);
        }
    });

    /**
     * Displays a generic confirmation modal.
     */
    function showConfirmModal(title, message, confirmCallback, isPositiveAction = true) {
        genericConfirmModalTitle.textContent = title;
        genericConfirmModalMessage.innerHTML = message;
        genericConfirmBtn.className = 'btn-confirm';
        if (!isPositiveAction) {
            genericConfirmBtn.classList.add('danger');
        }
        currentConfirmAction = confirmCallback;
        genericConfirmModal.classList.add('show');
    }

    // Event listener for generic confirm button
    genericConfirmBtn.addEventListener('click', () => {
        if (currentConfirmAction) {
            currentConfirmAction();
        }
        genericConfirmModal.classList.remove('show');
        currentConfirmAction = null;
    });

    // Event listener for generic cancel button
    genericCancelBtn.addEventListener('click', () => {
        genericConfirmModal.classList.remove('show');
        currentConfirmAction = null;
    });

    // Close generic modal if clicked outside content
    genericConfirmModal.addEventListener('click', function(e) {
        if (e.target === genericConfirmModal) {
            genericConfirmModal.classList.remove('show');
        }
    });

    // Function to show a temporary success or error message
    function showSuccessMessage(message, type = 'info') {
      const successMessageDiv = document.getElementById('successMessage');
      const span = successMessageDiv.querySelector('span');

      span.textContent = message;

      if (type === 'success') {
          successMessageDiv.style.background = 'linear-gradient(135deg, var(--success) 0%, #218838 100%)';
          successMessageDiv.style.borderColor = '#218838';
      } else if (type === 'danger') {
          successMessageDiv.style.background = 'linear-gradient(135deg, var(--danger) 0%, #C82333 100%)';
          successMessageDiv.style.borderColor = '#C82333';
      } else {
          successMessageDiv.style.background = 'linear-gradient(135deg, var(--info) 0%, #138496 100%)';
          successMessageDiv.style.borderColor = '#138496';
      }

      successMessageDiv.classList.add('show');
      setTimeout(() => {
        successMessageDiv.classList.remove('show');
      }, 3000);
    }

    // Function to handle check-in/out
    function handleCheckInOut(actionType) {
        checkInBtn.disabled = true;
        checkOutBtn.disabled = true;

        // MODIFIED: Pointing to the new process_member_activity.php
        fetch('process_member_activity.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${actionType}&member_id=${currentMemberId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessMessage(data.message, 'success');
                // Reload the page to reflect new status and update the log via PHP rendering
                location.reload(); // Reload to update status fields
            } else {
                showSuccessMessage(data.message, 'danger');
            }
        })
        .catch(error => {
            console.error('Error during check-in/out:', error);
            showSuccessMessage('An unexpected error occurred during status update.', 'danger');
        })
        .finally(() => {
            checkInBtn.disabled = false;
            checkOutBtn.disabled = false;
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

    // Change Password Button directs to M_ChangePassword.php directly
    changePasswordBtn.addEventListener('click', function() {
        window.location.href = 'M_ChangePassword.php';
    });

    // Check In/Out button click handlers
    checkInBtn.addEventListener('click', function() {
        showConfirmModal('Confirm Check In', 'Are you sure you want to mark yourself as "In"?', () => {
            handleCheckInOut('check_in');
        });
    });

    checkOutBtn.addEventListener('click', function() {
        showConfirmModal('Confirm Check Out', 'Are you sure you want to mark yourself as "Out"?', () => {
            handleCheckInOut('check_out');
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

    // Enhanced visitor card interactions
    function enhanceVisitorCards() {
        const visitorItems = document.querySelectorAll('.member-visitor-list-item');
        
        visitorItems.forEach((item, index) => {
            // Add staggered animation
            item.style.opacity = '0';
            item.style.transform = 'translateY(20px)';
            setTimeout(() => {
                item.style.transition = 'all 0.4s ease';
                item.style.opacity = '1';
                item.style.transform = 'translateY(0)';
            }, 100 + (index * 50));

            // Enhanced hover effects
            item.addEventListener('mouseenter', function() {
                this.style.background = 'linear-gradient(135deg, #ffffff 0%, #fefefe 100%)';
                this.style.boxShadow = '0 8px 25px rgba(139, 134, 78, 0.15)';
            });
            
            item.addEventListener('mouseleave', function() {
                this.style.background = 'var(--white)';
                this.style.boxShadow = 'var(--shadow-sm)';
            });

            // Enhanced remove button interaction
            const removeButton = item.querySelector('.btn-remove');
            if (removeButton) {
                removeButton.addEventListener('click', function(event) {
                    event.preventDefault();
                    
                    const originalText = this.textContent;
                    const visitorId = this.dataset.visitorId;
                    
                    // Show loading state
                    this.textContent = 'Removing...';
                    this.disabled = true;
                    this.style.opacity = '0.7';
                    
                    // Call enhanced remove function
                    removeVisitorFromMemberViewEnhanced(visitorId, this, originalText);
                });
            }
        });
    }

    // Enhanced removal function with better UX
    function removeVisitorFromMemberViewEnhanced(visitorId, buttonElement, originalText) {
        showConfirmModal(
            'Confirm Removal', 
            'Are you sure you want to remove this visitor from your list? This action cannot be undone.', 
            () => {
                fetch('process_member_visitor_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=remove_visitor_from_member_view&visitor_id=${visitorId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showSuccessMessage(data.message, 'success');
                        
                        // Enhanced removal animation
                        const visitorItem = document.querySelector(`.member-visitor-list-item[data-visitor-id='${visitorId}']`);
                        if (visitorItem) {
                            // Multi-stage removal animation
                            visitorItem.style.transition = 'all 0.3s ease-out';
                            visitorItem.style.transform = 'scale(0.95)';
                            visitorItem.style.opacity = '0.7';
                            
                            setTimeout(() => {
                                visitorItem.style.transform = 'translateX(-100%)';
                                visitorItem.style.opacity = '0';
                                visitorItem.style.maxHeight = '0';
                                visitorItem.style.padding = '0 28px';
                                visitorItem.style.margin = '0 20px';
                                
                                setTimeout(() => {
                                    visitorItem.remove();
                                    updateEmptyStateForVisitorsForMeEnhanced();
                                    
                                    // Re-animate remaining cards
                                    setTimeout(enhanceVisitorCards, 100);
                                }, 300);
                            }, 200);
                        }
                    } else {
                        // Reset button state on error
                        buttonElement.textContent = originalText;
                        buttonElement.disabled = false;
                        buttonElement.style.opacity = '1';
                        
                        if (data.redirect) {
                            window.location.href = data.redirect;
                        } else {
                            showSuccessMessage(data.message, 'danger');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error removing visitor:', error);
                    
                    // Reset button state on error
                    buttonElement.textContent = originalText;
                    buttonElement.disabled = false;
                    buttonElement.style.opacity = '1';
                    
                    showSuccessMessage('An unexpected error occurred while trying to remove the visitor.', 'danger');
                });
            }, 
            false // Destructive action
        );
        
        // Reset button if user cancels
        const resetButtonHandler = () => {
            buttonElement.textContent = originalText;
            buttonElement.disabled = false;
            buttonElement.style.opacity = '1';
        };
        
        genericCancelBtn.addEventListener('click', resetButtonHandler, { once: true });
        
        // Also reset if modal is closed by clicking outside
        genericConfirmModal.addEventListener('click', function(e) {
            if (e.target === genericConfirmModal) {
                resetButtonHandler();
            }
        }, { once: true });
    }

    // Enhanced empty state update with animation
    function updateEmptyStateForVisitorsForMeEnhanced() {
        const container = document.getElementById('acceptedVisitorsForMeContainer');
        const visitorItems = container.querySelectorAll('.member-visitor-list-item');
        const wrapperDiv = container.querySelector('.visitors-list-wrapper');
        let emptyStateDiv = container.querySelector('.empty-state');

        if (visitorItems.length === 0 && wrapperDiv) {
            // Remove the wrapper and add empty state
            wrapperDiv.style.transition = 'all 0.3s ease-out';
            wrapperDiv.style.opacity = '0';
            wrapperDiv.style.transform = 'translateY(-10px)';
            
            setTimeout(() => {
                wrapperDiv.remove();
                
                if (!emptyStateDiv) {
                    emptyStateDiv = document.createElement('div');
                    emptyStateDiv.className = 'empty-state';
                    emptyStateDiv.innerHTML = `
                        <div class='empty-state-icon'>👥</div>
                        <h3>No Accepted Visitors</h3>
                        <p>No visitors have been accepted to see you yet. When visitors are approved, they will appear here.</p>
                    `;
                    container.appendChild(emptyStateDiv);
                }
                
                // Animate empty state appearance
                emptyStateDiv.style.opacity = '0';
                emptyStateDiv.style.transform = 'translateY(20px)';
                emptyStateDiv.style.transition = 'all 0.4s ease-out';
                
                setTimeout(() => {
                    emptyStateDiv.style.opacity = '1';
                    emptyStateDiv.style.transform = 'translateY(0)';
                }, 50);
            }, 300);
        }
    }

    // Sidebar active link highlighting
    document.addEventListener('DOMContentLoaded', () => {
        const currentPage = window.location.pathname.split("/").pop();
        document.querySelectorAll('.sidebar nav li a').forEach(link => {
            // Check if href is an exact match or if current page is dashboard (empty string or M_Dashboard.php)
            if (link.getAttribute('href') === currentPage || 
               (link.getAttribute('href') === 'M_Dashboard.php' && (currentPage === '' || currentPage === 'index.php'))) {
                link.closest('li').classList.add('active');
            } else {
                link.closest('li').classList.remove('active');
            }
        });

        // Auto-hide flash messages after 3 seconds
        const phpFlashMessageDiv = document.getElementById('phpFlashMessage');
        if (phpFlashMessageDiv && phpFlashMessageDiv.classList.contains('show')) {
            const flashMessageContent = phpFlashMessageDiv.querySelector('span').textContent.trim();
            const flashMessageType = phpFlashMessageDiv.style.background.includes('success') ? 'success' : 'danger';
            if (flashMessageContent !== '') {
                showSuccessMessage(flashMessageContent, flashMessageType);
            }
            phpFlashMessageDiv.style.display = 'none';
        }

        // Initial page animation for profile card
        const profileCard = document.querySelector('.profile-card');
        if (profileCard) {
            profileCard.style.opacity = '0';
            profileCard.style.transform = 'translateY(20px)';
            setTimeout(() => {
                profileCard.style.transition = 'all 0.4s ease';
                profileCard.style.opacity = '1';
                profileCard.style.transform = 'translateY(0)';
            }, 50);
        }

        // Initial enhancement for visitor cards
        enhanceVisitorCards();
        
        // Add scroll smoothing for the visitors list
        const visitorsList = document.querySelector('.visitors-list-wrapper');
        if (visitorsList) {
            visitorsList.style.scrollBehavior = 'smooth';
        }
        
        // Add keyboard navigation support
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                // Close any open modals
                document.querySelectorAll('.modal.show').forEach(modal => {
                    modal.classList.remove('show');
                });
            }
        });
    });

    // Set global functions for backward compatibility
    window.removeVisitorFromMemberView = removeVisitorFromMemberViewEnhanced;
    window.updateEmptyStateForVisitorsForMe = updateEmptyStateForVisitorsForMeEnhanced;
  </script>
</body>
</html>