<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php';

function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if tenant is logged in
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("CRITICAL ERROR: Database connection failed in T_MemberRegistration.php");
    $_SESSION['flash_message'] = "Critical: Database connection failed. Please contact support.";
    $_SESSION['flash_message_type'] = "danger";
    header("Location: login.php"); // Or show an error page
    exit();
}

if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in to register members.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: login.php"); // Redirect to tenant login page
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

// Initialize form variables
$full_name = '';
$phone_number = '';
$relationship_to_tenant = '';
$username = '';
$password = '';
$confirm_password = '';
$known_face_path_2 = ''; // This will store the path AFTER successful form submission

// Initialize unit information
$tenant_unit_id = null;
$tenant_unit_number = 'N/A';

// Fetch the tenant's unit_id and unit_number
if (isset($conn) && $conn instanceof mysqli) {
    $stmt_unit_info = null;
    try {
        $sql_unit_info = "SELECT t.unit_id, u.unit_number
                          FROM tenants t
                          JOIN units u ON t.unit_id = u.unit_id
                          WHERE t.tenant_id = ?";
        $stmt_unit_info = $conn->prepare($sql_unit_info);
        if ($stmt_unit_info) {
            $stmt_unit_info->bind_param("i", $logged_in_tenant_id);
            $stmt_unit_info->execute();
            $result_unit_info = $stmt_unit_info->get_result();
            if ($result_unit_info->num_rows === 1) {
                $unit_data = $result_unit_info->fetch_assoc();
                $tenant_unit_id = $unit_data['unit_id'];
                $tenant_unit_number = e($unit_data['unit_number']);
            } else {
                error_log("No unit found for tenant ID: {$logged_in_tenant_id} in T_MemberRegistration.php");
                // Potentially redirect or show a critical error to the user
                $_SESSION['flash_message'] = "Error: Could not retrieve unit information.";
                $_SESSION['flash_message_type'] = "danger";
                header("Location: T_UnitProfile.php"); // Or another appropriate page
                exit();
            }
        } else {
            error_log("Failed to prepare statement for unit info in T_MemberRegistration: " . $conn->error);
        }
    } catch (Throwable $e) {
        error_log("Error fetching unit info for T_MemberRegistration: " . $e->getMessage());
    } finally {
        if ($stmt_unit_info) {
            $stmt_unit_info->close();
        }
    }
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

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'add_member') {
    $full_name = trim($_POST['full_name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $relationship_to_tenant = trim($_POST['relationship_to_tenant'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $registration_errors = [];

    // Basic input validation (all fields are required)
    if (empty($full_name)) {
        $registration_errors[] = "Full Name cannot be empty.";
    }
    if (empty($phone_number)) {
        $registration_errors[] = "Phone Number cannot be empty.";
    }
    if (empty($relationship_to_tenant)) {
        $registration_errors[] = "Relationship cannot be empty.";
    }
    if (empty($username)) {
        $registration_errors[] = "Username cannot be empty.";
    }

    // Password Validation - Server-Side
    if (empty($password)) {
        $registration_errors[] = "Password cannot be empty.";
    } elseif (strlen($password) < 8) {
        $registration_errors[] = "Password must be at least 8 characters long.";
    }
    // New: Server-side complexity checks
    if (!preg_match('/[a-z]/', $password)) {
        $registration_errors[] = "Password must contain at least one lowercase letter.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $registration_errors[] = "Password must contain at least one uppercase letter.";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $registration_errors[] = "Password must contain at least one number.";
    }
    if (!preg_match('/[^a-zA-Z0-9\s]/', $password)) { // Matches any character that is NOT a letter, number, or whitespace
        $registration_errors[] = "Password must contain at least one special character.";
    }

    // Confirm password validation
    if ($password !== $confirm_password) {
        $registration_errors[] = "Password and Confirm Password do not match.";
    }

    // Server-side validation for photo upload
    $profilePicPath = null;
    if (!isset($_FILES['facialImage']) || $_FILES['facialImage']['error'] == UPLOAD_ERR_NO_FILE || empty($_FILES['facialImage']['tmp_name'])) {
        $registration_errors[] = "A photo capture is required. Please capture a photo.";
    } else {
        if ($_FILES['facialImage']['error'] == UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['facialImage']['tmp_name'];
            $fileName = $_FILES['facialImage']['name'];
            $fileSize = $_FILES['facialImage']['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = './uploads/member_faces/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $newFileName = uniqid('member_photo_') . '.' . $fileExtension;
                $destPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $profilePicPath = $destPath;
                    $known_face_path_2 = $destPath;
                    // error_log("SUCCESS: Member photo uploaded to: " . $destPath); // Kept for debugging if needed
                } else {
                    $error_message_upload = "Error uploading photo image. ";
                    switch ($_FILES['facialImage']['error']) {
                        case UPLOAD_ERR_INI_SIZE: $error_message_upload .= "File too large (ini)."; break;
                        case UPLOAD_ERR_FORM_SIZE: $error_message_upload .= "File too large (form)."; break;
                        case UPLOAD_ERR_PARTIAL: $error_message_upload .= "File partially uploaded."; break;
                        case UPLOAD_ERR_NO_TMP_DIR: $error_message_upload .= "Missing temporary folder."; break;
                        case UPLOAD_ERR_CANT_WRITE: $error_message_upload .= "Failed to write file to disk. Check permissions."; break;
                        case UPLOAD_ERR_EXTENSION: $error_message_upload .= "A PHP extension stopped the file upload."; break;
                        case UPLOAD_ERR_NO_FILE: $error_message_upload .= "No file was uploaded."; break;
                        default: $error_message_upload .= "Unknown upload error (Code: " . $_FILES['facialImage']['error'] . ")."; break;
                    }
                    $registration_errors[] = $error_message_upload;
                    error_log("ERROR: Member photo upload failed - " . $error_message_upload . " | Temp: " . $fileTmpPath . " | Dest: " . $destPath);
                }
            } else {
                $registration_errors[] = "Invalid photo image format. Only JPG, JPEG, PNG, GIF allowed.";
            }
        } else {
            $registration_errors[] = "Error during photo image upload: " . $_FILES['facialImage']['error'];
        }
    }


    if (empty($registration_errors)) {
        if (isset($conn) && $conn instanceof mysqli) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Fetch tenant's unit_id to save with the new addedmember
            // This is crucial if 'addedmembers' table does not have a unit_id column directly
            // but for simplicity, we'll assume it exists now after ALTER TABLE
            // If it doesn't exist, you MUST fetch it here as well for the INSERT statement.
            if ($tenant_unit_id === null) {
                // This scenario should ideally be caught earlier in page load, but as a fallback
                error_log("Unit ID for tenant {$logged_in_tenant_id} not found during form submission.");
                $_SESSION['flash_message'] = "Error: Could not determine unit for member registration.";
                $_SESSION['flash_message_type'] = "danger";
                header("Location: T_MemberRegistration.php");
                exit();
            }


            // MODIFIED: Added unit_id to the INSERT statement for addedmembers table
            $insert_sql = "INSERT INTO addedmembers (tenant_id, unit_id, full_name, username, password_hash, phone_number, relationship_to_tenant, known_face_path_2) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_insert = $conn->prepare($insert_sql);

            if ($stmt_insert) {
                // 'iissssss' -> integer, integer, string, string, string, string, string, string
                $stmt_insert->bind_param("iissssss",
                    $logged_in_tenant_id,
                    $tenant_unit_id, // Pass the fetched unit_id here
                    $full_name,
                    $username,
                    $hashed_password,
                    $phone_number,
                    $relationship_to_tenant,
                    $known_face_path_2
                );

                try { // Add try-catch block to gracefully handle database exceptions
                    if ($stmt_insert->execute()) {
                        $_SESSION['flash_message'] = "Member '" . e($full_name) . "' registered successfully!";
                        $_SESSION['flash_message_type'] = "success";
                        // Clear form fields on successful submission
                        $full_name = '';
                        $phone_number = '';
                        $relationship_to_tenant = '';
                        $username = '';
                        $password = '';
                        $confirm_password = '';
                        $known_face_path_2 = ''; // Clear for fresh form
                    }
                } catch (mysqli_sql_exception $e) {
                    // Check for duplicate entry error (MySQL error code 1062)
                    if ($e->getCode() == 1062) {
                        $_SESSION['flash_message'] = "Error: Username '" . e($username) . "' is already taken. Please choose a different one.";
                        $_SESSION['flash_message_type'] = "danger";
                        error_log("Duplicate username registration attempt: " . $username);
                    } else {
                        // Other database errors
                        $_SESSION['flash_message'] = "Error registering member: " . $e->getMessage();
                        $_SESSION['flash_message_type'] = "danger";
                        error_log("Database error during member registration: " . $e->getMessage());
                    }
                } finally {
                    $stmt_insert->close();
                }
            } else {
                $_SESSION['flash_message'] = "Database error: Could not prepare insert statement.";
                $_SESSION['flash_message_type'] = "danger";
                error_log("Failed to prepare insert statement for T_MemberRegistration (addedmembers): " . $conn->error);
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Unable to register member.";
            $_SESSION['flash_message_type'] = "danger";
        }
    } else {
        // If there are validation errors, store them in flash message
        $_SESSION['flash_message'] = implode("<br>", $registration_errors);
        $_SESSION['flash_message_type'] = "danger";
    }

    // Redirect to self to prevent form resubmission on refresh (PRG pattern)
    header("Location: T_MemberRegistration.php");
    exit();
}

// Fetch pending count for the sidebar badge
$pending_badge_count = 0;
if (isset($conn) && $conn instanceof mysqli) {
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
        error_log("Error fetching pending count for T_MemberRegistration: " . $e->getMessage());
    } finally {
        if ($stmt_pending_count) {
            $stmt_pending_count->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verifaced: Member Registration</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* ... (Your existing CSS) ... */
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
      /* REMOVED: display: flex; */
      /* REMOVED: min-height: 100vh; */
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
      position: fixed; /* Changed to fixed */
      top: 0; /* Added */
      left: 0; /* Added */
      height: 100vh; /* Added */
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
      margin-left: 240px; /* Added for fixed sidebar */
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

    /* FORM CARD STYLING (similar to unit-profile-card) */
    .form-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--gray-200);
      transition: var(--transition-normal);
      position: relative;
      overflow: hidden;
      max-width: 600px; /* Constrain width for better readability */
      margin: 0 auto 24px auto; /* Center the card */
    }

    .form-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }

    .form-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    /* Image upload group for members */
    .image-upload-group {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
        margin-bottom: 24px; /* Adjusted margin */
        border: 1px dashed var(--gray-300); /* Visual separation */
        padding: 15px;
        border-radius: var(--radius-md);
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

    .profile-image-upload-btn {
        padding: 8px 16px !important;
        font-size: 13px !important;
        background: var(--button-neutral-bg);
        color: var(--button-neutral-text);
        border: 1px solid var(--button-neutral-border);
        border-radius: var(--radius-md);
        cursor: pointer;
        transition: var(--transition-normal);
        box-shadow: var(--shadow-sm);
    }
    .profile-image-upload-btn:hover {
        background: var(--button-neutral-hover-bg);
        color: var(--button-neutral-hover-text);
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .image-upload-group .detail-label {
        margin-bottom: 0;
    }


    .profile-details {
      display: grid;
      grid-template-columns: 1fr; /* Single column for simplicity */
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

    .detail-input, .detail-select {
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

    .detail-input:focus, .detail-select:focus {
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

    .detail-input:read-only { /* Style for read-only fields */
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
      margin-top: 24px; /* More space before buttons */
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

    .btn-register {
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      color: var(--white);
    }

    .btn-register:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
      background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
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

    /* MODAL STYLES (for logout and generic confirms, and now face scan) */
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
      max-width: 600px; /* Increased max-width for camera feed */
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

    /* Face Scan Specific Styles from visitorform.php */
    .modal-body {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 20px;
        margin-bottom: 20px;
    }

    .camera-feed-container {
        position: relative;
        width: 100%;
        padding-top: 75%; /* 4:3 aspect ratio (3/4 * 100%) */
        background-color: var(--gray-900);
        border-radius: var(--radius-md);
        overflow: hidden;
        box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
        border: 2px solid var(--gray-700);
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .camera-feed-container video,
    .camera-feed-container img {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: var(--radius-md);
    }

    .camera-feed-container canvas {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border-radius: var(--radius-md);
    }

    #photoCaptureMessage { /* Updated ID */
        font-size: 14px;
        color: var(--gray-600);
        min-height: 20px;
        text-align: center;
    }

    .modal-actions {
        display: flex;
        justify-content: center;
        gap: 15px;
    }
    .modal-actions .btn-primary, .modal-actions .btn-secondary {
        min-width: unset; /* Override button min-width for modal */
    }


    /* RESPONSIVE DESIGN */
    @media (max-width: 1024px) {
      .sidebar { width: 200px; }
      .sidebar h2 { font-size: 20px; }
      .sidebar nav li a { font-size: 12px; padding: 10px 14px; gap: 10px; }
      .logo-circle { width: 44px; height: 44px; font-size: 16px; }
      .main-content {
        padding: 20px;
        margin-left: 200px; /* Adjusted for smaller sidebar */
      }
      .form-card { padding: 20px; max-width: 500px; }
      .profile-image { width: 100px; height: 100px; }
      .detail-label { font-size: 10px; }
      .detail-input, .detail-select { font-size: 13px; padding: 10px 14px; }
      .action-buttons { flex-direction: row; gap: 10px; }
      .btn { padding: 8px 16px; font-size: 13px; min-width: 90px; }
      .content-header h1 { font-size: 24px; }
      /* Modal Responsive */
      .modal-content { max-width: 500px; padding: 20px;}
      .modal-header h3 { font-size: 20px;}
      .modal-actions .btn { font-size: 13px; padding: 8px 16px;}
    }

    @media (max-width: 768px) {
      .container { flex-direction: column; }
      .sidebar {
        width: 100%;
        height: auto;
        padding: 16px 0;
        box-shadow: var(--shadow-md);
        position: static; /* Revert to static */
        height: auto; /* Revert height */
      }
      .sidebar nav ul { display: flex; gap: 6px; overflow-x: auto; padding: 0 12px; justify-content: flex-start; }
      .sidebar nav li { white-space: nowrap; min-width: auto; }
      .sidebar nav li a { padding: 10px 14px; font-size: 12px; }
      .logout { position: static; margin-top: 16px; margin-left: auto; margin-right: auto; max-width: 160px; }
      .main-content {
        padding: 16px;
        margin-left: 0; /* Remove margin-left */
      }
      .content-header h1 { font-size: 24px; }
      .form-card { margin: 0 auto 16px auto; }
      .profile-image { width: 100px; height: 100px; }
      .profile-image-upload-btn { padding: 6px 12px !important; font-size: 12px !important; }
      .action-buttons { flex-direction: column; gap: 8px; }
      .btn { min-width: unset; width: 100%; }
      .modal-content { margin: 16px; padding: 20px; }
      /* Modal Responsive */
      .modal-content { max-width: 90%; padding: 15px;}
      .modal-actions {flex-direction: column; gap: 10px;}
      .modal-actions .btn {width: 100%;}
    }

    @media (max-width: 480px) {
      body { font-size: 13px; }
      .main-content { padding: 12px; }
      .content-header h1 { font-size: 20px; }
      .form-card { padding: 16px; }
      .profile-image { width: 80px; height: 80px; }
      .detail-input, .detail-select { font-size: 12px; padding: 8px 12px; }
      .action-buttons { gap: 8px; }
      .btn { font-size: 13px; padding: 8px 15px; }
      .success-message { padding: 10px 15px; font-size: 12px; bottom: 16px; }
      .modal-content { padding: 16px; }
      .modal-content h3 { font-size: 16px; }
      .modal-content p { font-size: 13px; }
      .modal-buttons button { padding: 8px 15px; font-size: 12px; }
      /* Modal Responsive */
      .modal-actions .btn { font-size: 12px; padding: 6px 12px;}
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

    .form-card {
      animation: fadeInUp 0.6s ease-out;
    }

    /* FOCUS STYLES FOR ACCESSIBILITY */
    .btn:focus,
    .detail-input:focus,
    .detail-select:focus,
    .sidebar nav li a:focus,
    .modal-buttons button:focus,
    .logout:focus,
    .profile-image-upload-btn:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES */
    .btn:disabled,
    .modal-buttons button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
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
          <li><a href="T_UnitProfile.php"><span></span> Unit Profile</a></li>
          <li><a href="T_VisitorsList.php"><span></span> Visitors List</a></li>
          <li><a href="T_Pending.php"><span></span> Pending <span class="badge" id="pendingBadgeMemberReg"><?php echo $pending_badge_count; ?></span></a></li>
          <li class="active"><a href="T_MemberRegistration.php"><span></span> Member Registration</a></li>
        </ul>
      </nav>
      <a href="#" class="logout" id="logoutLink">Log out</a>
    </aside>

    <main class="main-content">
      <div class="content-header">
        <h1>Member Registration</h1>
        <p>Register new permanent members for your unit.</p>
      </div>

      <?php if ($flash_message): // Display flash message ?>
          <div id="phpFlashMessage" class='success-message <?php echo ($flash_message ? "show" : ""); ?>' style="background: <?php echo ($flash_message_type === 'success' ? 'linear-gradient(135deg, var(--success) 0%, #218838 100%)' : 'linear-gradient(135deg, var(--danger) 0%, #C82333 100%)'); ?>; color: white; border-color: <?php echo ($flash_message_type === 'success' ? '#218838' : '#C82333'); ?>;">
            <span><?php echo $flash_message; ?></span>
          </div>
      <?php endif; ?>

      <form id="memberRegistrationForm" method="POST" action="T_MemberRegistration.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_member">
        <div class="form-card">

          <div class="image-upload-group">
            <div class="profile-image">
                <img src="https://placehold.co/120x120/A0A0A0/FFFFFF?text=Member+Photo" alt="Member Photo" id="memberFacePreview" onerror="this.onerror=null;this.src='https://placehold.co/120x120/A0A0A0/FFFFFF?text=Member+Photo';">
            </div>
            <input type="file" id="facialImage" name="facialImage" accept="image/*" style="display:none;">
            <button type="button" class="btn profile-image-upload-btn" id="openFaceScanModalBtn">Capture Photo</button>
            <small id="facialImageStatus" style="font-size:12px; color:var(--gray-500); margin-top:4px; text-align: center;"></small>
          </div>
          <div class="profile-details">
            <div class="detail-group">
              <label class="detail-label">Your Unit Number</label>
              <input type="text" class="detail-input" value="<?php echo $tenant_unit_number; ?>" readonly>
            </div>

            <div class="detail-group">
              <label class="detail-label" for="fullNameInput">Full Name<span style="color: var(--danger); font-size: 1.2em; vertical-align: middle; margin-left: 5px;">*</span></label>
              <input type="text" class="detail-input" id="fullNameInput" name="full_name" placeholder="Enter full name" value="<?php echo e($full_name); ?>" required>
            </div>

            <div class="detail-group">
              <label class="detail-label" for="usernameInput">Username<span style="color: var(--danger); font-size: 1.2em; vertical-align: middle; margin-left: 5px;">*</span></label>
              <input type="text" class="detail-input" id="usernameInput" name="username" placeholder="Choose a username" value="<?php echo e($username); ?>" required>
            </div>

            <div class="detail-group">
              <label class="detail-label" for="passwordInput">Password<span style="color: var(--danger); font-size: 1.2em; vertical-align: middle; margin-left: 5px;">*</span></label>
              <input type="password" class="detail-input" id="passwordInput" name="password" placeholder="Set a password" value="<?php echo e($password); ?>" required>
            </div>

            <div class="detail-group">
              <label class="detail-label" for="confirmPasswordInput">Confirm Password<span style="color: var(--danger); font-size: 1.2em; vertical-align: middle; margin-left: 5px;">*</span></label>
              <input type="password" class="detail-input" id="confirmPasswordInput" name="confirm_password" placeholder="Confirm your password" value="<?php echo e($confirm_password); ?>" required>
            </div>

            <div class="detail-group">
              <label class="detail-label" for="phoneNumberInput">Phone Number<span style="color: var(--danger); font-size: 1.2em; vertical-align: middle; margin-left: 5px;">*</span></label>
              <input type="tel" class="detail-input" id="phoneNumberInput" name="phone_number" placeholder="Enter phone number" value="<?php echo e($phone_number); ?>" required>
            </div>

            <div class="detail-group">
              <label class="detail-label" for="relationshipInput">Relationship to Tenant<span style="color: var(--danger); font-size: 1.2em; vertical-align: middle; margin-left: 5px;">*</span></label>
              <select class="detail-select" id="relationshipInput" name="relationship_to_tenant" required>
                <option value="">Select Relationship</option>
                <option value="Parent" <?php echo ($relationship_to_tenant == 'Parent' ? 'selected' : ''); ?>>Parent</option>
                <option value="Sibling" <?php echo ($relationship_to_tenant == 'Sibling' ? 'selected' : ''); ?>>Sibling</option>
                <option value="Child" <?php echo ($relationship_to_tenant == 'Child' ? 'selected' : ''); ?>>Child</option>
                <option value="Spouse" <?php echo ($relationship_to_tenant == 'Spouse' ? 'selected' : ''); ?>>Spouse</option>
                <option value="Cousin" <?php echo ($relationship_to_tenant == 'Cousin' ? 'selected' : ''); ?>>Cousin</option>
                <option value="Friend" <?php echo ($relationship_to_tenant == 'Friend' ? 'selected' : ''); ?>>Friend</option>
              </select>
            </div>
          </div>
          <div class="action-buttons">
              <button type="submit" class="btn btn-register">Register Member</button>
          </div>
        </div>
      </form>

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

  <div id="photoCaptureModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Capture Member Photo</h3>
        </div>
        <div class="modal-body">
            <div class="camera-feed-container">
                <video id="cameraFeedVideo" autoplay playsinline style="display:none;"></video>
                <canvas id="captureCanvas" style="display:none;"></canvas>
                </div>
            <p id="photoCaptureMessage">Please allow camera access and position yourself for the photo.</p>
            <div class="modal-actions">
                <button type="button" class="btn btn-primary" id="capturePhotoBtnModal">Capture Photo</button>
                <button type="button" class="btn btn-secondary" id="retakePhotoBtnModal" style="display:none;">Retake Photo</button>
                <button type="button" class="btn btn-secondary" id="closeModalBtn">✕ Done/Close</button>
            </div>
            </div>
    </div>
  </div>
  <script>
    // Get references to DOM elements
    const logoutLink = document.getElementById('logoutLink');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogoutBtn = document.getElementById('confirmLogout');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    const pendingBadgeMemberReg = document.getElementById('pendingBadgeMemberReg');
    const successMessage = document.getElementById('successMessage');

    // Generic Confirmation Modal Elements (copied from T_UnitProfile.php for consistency)
    const genericConfirmModal = document.getElementById('genericConfirmModal');
    const genericConfirmModalTitle = document.getElementById('genericConfirmModalTitle');
    const genericConfirmModalMessage = document.getElementById('genericConfirmModalMessage');
    const genericConfirmBtn = document.getElementById('genericConfirmBtn');
    const genericCancelBtn = document.getElementById('genericCancelBtn');
    let currentConfirmAction = null; // To store the callback function for the current confirmation

    // New: Photo Capture elements
    const openFaceScanModalBtn = document.getElementById('openFaceScanModalBtn'); // Button on the main form
    const facialImageInput = document.getElementById('facialImage'); // Hidden file input in main form
    const facialImageStatus = document.getElementById('facialImageStatus'); // Small text below button in main form
    const memberFacePreview = document.getElementById('memberFacePreview'); // Image in form card on main form

    // Modal elements for photo capture
    const photoCaptureModal = document.getElementById('photoCaptureModal'); // The modal itself
    const cameraFeedVideo = document.getElementById('cameraFeedVideo');
    const captureCanvas = document.getElementById('captureCanvas');
    const photoCaptureMessage = document.getElementById('photoCaptureMessage'); // Message inside the modal
    const capturePhotoBtnModal = document.getElementById('capturePhotoBtnModal'); // "Capture Photo" button inside modal
    const retakePhotoBtnModal = document.getElementById('retakePhotoBtnModal'); // "Retake Photo" button inside modal
    const closeModalBtn = document.getElementById('closeModalBtn'); // "Done/Close" button inside modal

    // Context for canvas drawing
    const captureCtx = captureCanvas.getContext('2d');

    let photoCaptured = false;
    let stream = null;
    let capturedImageDataUrl = null;

    async function resetModal() {
        photoCaptured = false;
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            cameraFeedVideo.srcObject = null;
            stream = null; // Clear the stream variable
        }
        cameraFeedVideo.style.display = 'none';
        captureCanvas.style.display = 'none';

        photoCaptureMessage.textContent = 'Please allow camera access and position yourself for the photo.';
        capturePhotoBtnModal.style.display = 'inline-flex';
        capturePhotoBtnModal.disabled = false;
        retakePhotoBtnModal.style.display = 'none';
        captureCtx.clearRect(0, 0, captureCanvas.width, captureCanvas.height);
        capturedImageDataUrl = null;

        // Reset the facialImageInput and status when starting a new capture or retaking
        facialImageInput.value = ''; // Clears the file input selection
        facialImageInput.files = new DataTransfer().files; // Ensures the FileList is empty
        facialImageStatus.textContent = ''; // Clear status text on the main form
        memberFacePreview.src = 'https://placehold.co/120x120/A0A0A0/FFFFFF?text=Member+Photo'; // Reset preview image
    }


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

    // Logout Modal Logic
    logoutLink.addEventListener('click', function (e) {
      e.preventDefault();
      logoutModal.classList.add('show');
    });

    confirmLogoutBtn.addEventListener('click', function () {
      // Add logout animation
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

    // --- Photo Capture Button Logic (adapted from previous version) ---
    openFaceScanModalBtn.addEventListener('click', async function() {
        photoCaptureModal.classList.add('show');
        await resetModal(); // Ensure modal is fully reset before opening camera

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
            cameraFeedVideo.srcObject = stream;
            cameraFeedVideo.style.display = 'block';
            photoCaptureMessage.textContent = 'Camera active. Position yourself for the photo.';

            cameraFeedVideo.onloadedmetadata = () => {
                cameraFeedVideo.play();
                captureCanvas.width = cameraFeedVideo.videoWidth;
                captureCanvas.height = cameraFeedVideo.videoHeight;
            };

        } catch (err) {
            console.error('Error accessing camera:', err);
            photoCaptureMessage.textContent = 'Could not access camera. Please allow permissions.';
            photoCaptureMessage.style.color = 'var(--danger)';
            capturePhotoBtnModal.disabled = true;
            showSuccessMessage('Camera access denied or not available. Please ensure your camera is connected and not in use by another application.', 'danger');
        }
    });

    capturePhotoBtnModal.addEventListener('click', async function() {
        if (!stream || cameraFeedVideo.paused) {
            photoCaptureMessage.textContent = 'Camera not active. Please retry.';
            return;
        }

        // Draw video frame to canvas
        captureCtx.drawImage(cameraFeedVideo, 0, 0, captureCanvas.width, captureCanvas.height);
        capturedImageDataUrl = captureCanvas.toDataURL('image/png'); // Get image as Base64

        photoCaptured = true;
        cameraFeedVideo.pause();
        cameraFeedVideo.style.display = 'none'; // Hide video feed
        captureCanvas.style.display = 'block'; // Show captured image

        photoCaptureMessage.textContent = 'Photo captured! Review the image below.';

        capturePhotoBtnModal.style.display = 'none';
        retakePhotoBtnModal.style.display = 'inline-flex';

        // Set facialImage input with the captured photo for form submission
        try {
            const response = await fetch(capturedImageDataUrl);
            const blob = await response.blob();
            // Create a File object from the blob
            const file = new File([blob], 'captured_member_photo.png', { type: 'image/png' });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            facialImageInput.files = dataTransfer.files;

            // Update the preview image in the main form
            memberFacePreview.src = capturedImageDataUrl;

            facialImageStatus.textContent = 'Photo captured. Ready for submission.';
            facialImageStatus.style.color = 'var(--success)';
        } catch (error) {
            console.error('Error setting captured image to file input:', error);
            facialImageStatus.textContent = 'Error capturing photo.';
            facialImageStatus.style.color = 'var(--danger)';
        }
    });

    retakePhotoBtnModal.addEventListener('click', function() {
        // Reset everything for a new capture within the modal
        resetModal();
        openFaceScanModalBtn.click(); // Re-trigger modal open and camera
    });

    closeModalBtn.addEventListener('click', function() {
        photoCaptureModal.classList.remove('show');
        // Stop camera stream to release resources
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            cameraFeedVideo.srcObject = null;
            stream = null;
        }
        // Ensure that if a photo was captured, it remains displayed on the main form,
        // and the video feed/canvases in the modal are hidden.
        cameraFeedVideo.style.display = 'none';
        // The captureCanvas remains visible if photoCaptured is true, otherwise it's hidden by resetModal() on next open
        // No explicit hide for captureCanvas here because if photo was taken, we want to keep it displayed.
    });
    // --- End Photo Capture Button Logic ---


    // Input focus animations (for consistency)
    document.querySelectorAll('.detail-input, .detail-select').forEach(input => {
      input.addEventListener('focus', function() {
        this.closest('.detail-group').style.transform = 'translateY(-1px)';
      });

      input.addEventListener('blur', function() {
        this.closest('.detail-group').style.transform = 'translateY(0)';
      });
    });

    // Client-side form validation
    document.getElementById('memberRegistrationForm').addEventListener('submit', function(event) {
        const password = document.getElementById('passwordInput').value;
        const confirmPassword = document.getElementById('confirmPasswordInput').value;
        const facialImageFiles = document.getElementById('facialImage').files; // Get FileList from input

        let isValid = true;
        let messages = [];

        // Reset borders for re-validation
        document.getElementById('fullNameInput').style.borderColor = '';
        document.getElementById('usernameInput').style.borderColor = '';
        document.getElementById('passwordInput').style.borderColor = '';
        document.getElementById('confirmPasswordInput').style.borderColor = '';
        document.getElementById('phoneNumberInput').style.borderColor = '';
        document.getElementById('relationshipInput').style.borderColor = '';
        facialImageStatus.style.color = 'var(--gray-500)';


        // Basic Field Validation
        if (!document.getElementById('fullNameInput').value.trim()) {
            messages.push('Full Name is required.');
            isValid = false;
            document.getElementById('fullNameInput').style.borderColor = 'red';
        }
        if (!document.getElementById('usernameInput').value.trim()) {
            messages.push('Username is required.');
            isValid = false;
            document.getElementById('usernameInput').style.borderColor = 'red';
        }
        if (!document.getElementById('phoneNumberInput').value.trim()) {
            messages.push('Phone Number is required.');
            isValid = false;
            document.getElementById('phoneNumberInput').style.borderColor = 'red';
        }
        if (!document.getElementById('relationshipInput').value) {
            messages.push('Relationship to Tenant is required.');
            isValid = false;
            document.getElementById('relationshipInput').style.borderColor = 'red';
        }

        // Password Validation - Client-Side (Improved)
        if (!password) {
            messages.push('Password is required.');
            isValid = false;
            document.getElementById('passwordInput').style.borderColor = 'red';
        } else {
            if (password.length < 8) {
                messages.push('Password must be at least 8 characters long.');
                isValid = false;
                document.getElementById('passwordInput').style.borderColor = 'red';
            }
            if (!/[a-z]/.test(password)) {
                messages.push('Password must contain at least one lowercase letter.');
                isValid = false;
                document.getElementById('passwordInput').style.borderColor = 'red';
            }
            if (!/[A-Z]/.test(password)) {
                messages.push('Password must contain at least one uppercase letter.');
                isValid = false;
                document.getElementById('passwordInput').style.borderColor = 'red';
            }
            if (!/[0-9]/.test(password)) {
                messages.push('Password must contain at least one number.');
                isValid = false;
                document.getElementById('passwordInput').style.borderColor = 'red';
            }
            // Matches any character that is NOT a letter, number, or whitespace.
            // \s includes space, tab, newline, etc.
            if (!/[^a-zA-Z0-9\s]/.test(password)) {
                messages.push('Password must contain at least one special character (e.g., !, @, #, $).');
                isValid = false;
                document.getElementById('passwordInput').style.borderColor = 'red';
            }
        }


        if (!confirmPassword) {
            messages.push('Confirm Password is required.');
            isValid = false;
            document.getElementById('confirmPasswordInput').style.borderColor = 'red';
        } else if (password !== confirmPassword) {
            messages.push('Password and Confirm Password do not match.');
            isValid = false;
            document.getElementById('confirmPasswordInput').style.borderColor = 'red';
            // Also highlight password input if they don't match
            document.getElementById('passwordInput').style.borderColor = 'red';
        }

        // Phone Number Format Validation (exactly 11 digits)
        const phoneNumber = document.getElementById('phoneNumberInput').value;
        if (phoneNumber && !/^\d{11}$/.test(phoneNumber)) {
            messages.push('Phone number must be exactly 11 digits and contain only numbers.');
            isValid = false;
            document.getElementById('phoneNumberInput').style.borderColor = 'red';
        }

        // Photo Capture Validation
        if (facialImageFiles.length === 0 || facialImageFiles[0].size === 0) {
            messages.push('A member photo is required. Please click "Capture Photo" and capture their photo.');
            isValid = false;
            facialImageStatus.textContent = 'Photo is required.';
            facialImageStatus.style.color = 'var(--danger)';
        }


        if (!isValid) {
            showConfirmModal("Validation Error", messages.join('<br>'), () => {}, true); // Display all accumulated messages using the custom modal
            event.preventDefault(); // Stop form submission
        }
    });

    // Sidebar navigation (ensure "Member Registration" is active)
    document.addEventListener('DOMContentLoaded', function() {
      const currentPath = window.location.pathname.split('/').pop();
      document.querySelectorAll('.sidebar nav li a').forEach(link => {
        if (link.getAttribute('href') === currentPath) {
          link.closest('li').classList.add('active');
        } else {
          link.closest('li').classList.remove('active');
        }
      });

      // Initial page animation for the form card
      const formCard = document.querySelector('.form-card');
      if (formCard) {
          formCard.style.opacity = '0';
          formCard.style.transform = 'translateY(20px)';
          setTimeout(() => {
            formCard.style.transition = 'all 0.4s ease';
            formCard.style.opacity = '1';
            formCard.style.transform = 'translateY(0)';
          }, 50);
      }

      // Handle initial PHP-generated flash message
      const phpFlashMessageDiv = document.getElementById('phpFlashMessage');
      const flashMessageContent = phpFlashMessageDiv ? phpFlashMessageDiv.querySelector('span').textContent.trim() : '';

      if (phpFlashMessageDiv && flashMessageContent !== '') {
          showSuccessMessage(flashMessageContent);
      }
    });
  </script>
</body>
</html>