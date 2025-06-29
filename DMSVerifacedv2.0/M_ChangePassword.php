// M_ChangePassword.php
<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php'; // Ensure this file creates a $conn mysqli object

// Helper function to escape HTML for output
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if added member is logged in
if (!isset($_SESSION['added_member_logged_in']) || $_SESSION['added_member_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in as a member to change your password.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: memberlogin.php"); // Redirect to member login page
    exit();
}

$logged_in_added_member_id = $_SESSION['added_member_id'];

// Initialize variables for flash messages
$flash_message = '';
$flash_message_type = '';

// Fetch Pending count for badge (placeholder for member section if needed, currently 0)
// For members, this might be a different count, e.g., assigned tasks, but for design consistency,
// we'll keep the structure even if the count is always 0 for now.
$pending_badge_count = 0; // Members might not have "pending visitors" like tenants do

// Handle password change form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Using names from the HTML form: current_password, new_password, confirm_new_password
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_new_password = $_POST['confirm_new_password'] ?? '';

    $errors = [];

    // 1. Validate inputs
    if (empty($current_password)) {
        $errors[] = "Current Password is required.";
    }
    if (empty($new_password)) {
        $errors[] = "New Password is required.";
    }
    if (empty($confirm_new_password)) {
        $errors[] = "Confirm New Password is required.";
    }
    if ($new_password !== $confirm_new_password) {
        $errors[] = "New Password and Confirm New Password do not match.";
    }
    if (strlen($new_password) < 8) {
        $errors[] = "New Password must be at least 8 characters long.";
    }
    // Password complexity validation: at least one lowercase, one uppercase, one digit, one special char
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/', $new_password)) {
        $errors[] = "New Password must contain at least one lowercase letter, one uppercase letter, one number, and one special character.";
    }


    if (empty($errors)) {
        $stmt_check_password = null;
        $stmt_update_password = null;
        try {
            if (isset($conn) && $conn instanceof mysqli) {
                // 2. Fetch current password hash from the database (from 'addedmembers' table)
                $sql_get_hash = "SELECT password_hash FROM addedmembers WHERE added_member_id = ? LIMIT 1";
                $stmt_check_password = $conn->prepare($sql_get_hash);
                if ($stmt_check_password) {
                    $stmt_check_password->bind_param("i", $logged_in_added_member_id);
                    $stmt_check_password->execute();
                    $result = $stmt_check_password->get_result();
                    $member = $result->fetch_assoc();

                    if ($member) {
                        // 3. Verify current password
                        if (password_verify($current_password, $member['password_hash'])) {
                            // 4. Hash the new password
                            $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);

                            // 5. Update the database with the new password hash
                            $sql_update_password = "UPDATE addedmembers SET password_hash = ? WHERE added_member_id = ?";
                            $stmt_update_password = $conn->prepare($sql_update_password);
                            if ($stmt_update_password) {
                                $stmt_update_password->bind_param("si", $hashed_new_password, $logged_in_added_member_id);
                                if ($stmt_update_password->execute()) {
                                    $_SESSION['flash_message'] = "Your password has been changed successfully!";
                                    $_SESSION['flash_message_type'] = "success";
                                    // Redirect back to dashboard after successful change
                                    header("Location: M_Dashboard.php");
                                    exit();
                                } else {
                                    $errors[] = "Error updating password: " . $stmt_update_password->error;
                                }
                            } else {
                                $errors[] = "Database error: Could not prepare update statement.";
                                error_log("Failed to prepare update password statement for M_ChangePassword: " . $conn->error);
                            }
                        } else {
                            $errors[] = "Incorrect current password.";
                        }
                    } else {
                        $errors[] = "Member not found. Please log in again.";
                        // In case member ID is not found, redirect to login
                        header("Location: memberlogin.php");
                        exit();
                    }
                } else {
                    $errors[] = "Database error: Could not prepare password retrieval statement.";
                    error_log("Failed to prepare password retrieval statement for M_ChangePassword: " . $conn->error);
                }
            } else {
                $errors[] = "Database connection error. Unable to change password.";
            }
        } catch (Throwable $e) {
            error_log("Password change error in M_ChangePassword: " . $e->getMessage());
            $errors[] = "An unexpected error occurred. Please try again later.";
        } finally {
            if ($stmt_check_password) {
                $stmt_check_password->close();
            }
            if ($stmt_update_password) {
                $stmt_update_password->close();
            }
        }
    }

    // If there are validation errors, store them in a flash message
    if (!empty($errors)) {
        $_SESSION['flash_message'] = implode("<br>", $errors);
        $_SESSION['flash_message_type'] = "danger";
    }
    // Redirect to self to display messages and prevent form resubmission (PRG pattern)
    header("Location: M_ChangePassword.php");
    exit();
}

// After POST handling, retrieve flash messages if any for display on GET request
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
  <title>Verifaced: Member Change Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* COMPACT PROFESSIONAL KHAKI THEME CSS - START */
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
      --button-neutral-bg: #F5F0E1; /* Light cream/khaki */
      --button-neutral-text: #8B7D6B; /* Darker khaki/brown */
      --button-neutral-border: #E6DCC6; /* Subtle border color */
      --button-neutral-hover-bg: #EAE5D6; /* Slightly darker on hover */
      --button-neutral-hover-text: #6B5B4F; /* Even darker text on hover */

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
      display: flex; /* Changed to flex to align sidebar and main content */
      min-height: 100vh;
    }

    /* COMPACT SIDEBAR STYLES (Copied from T_ChangePassword.php) */
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

    /* COMPACT MAIN CONTENT STYLES (Copied from T_ChangePassword.php) */
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

    /* COMPACT MESSAGE SYSTEM (Copied from T_ChangePassword.php) */
    .app-message {
      padding: 12px 16px;
      border-radius: var(--radius-md);
      margin: 0 auto 16px auto;
      font-weight: 600;
      display: none;
      text-align: center;
      max-width: 500px;
      box-shadow: var(--shadow-sm);
      border: 1px solid transparent;
      font-size: 13px;
    }

    .app-message.success {
      background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.05) 100%);
      color: var(--success);
      border-color: rgba(40, 167, 69, 0.2);
    }

    .app-message.danger {
      background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
      color: var(--danger);
      border-color: rgba(220, 53, 69, 0.2);
    }

    .app-message.warning {
      background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 193, 7, 0.05) 100%);
      color: var(--warning);
      border-color: rgba(255, 193, 7, 0.2);
    }

    .app-message.show {
      display: block;
      animation: fadeInMessage 0.3s ease-out;
    }

    @keyframes fadeInMessage {
      from {
        opacity: 0;
        transform: translateY(-8px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* COMPACT MODALS (Copied from T_ChangePassword.php) */
    .modal {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background-color: rgba(33, 37, 41, 0.5);
      backdrop-filter: blur(4px);
      z-index: 1000;
      align-items: center;
      justify-content: center;
      padding: 16px;
    }

    .modal.show {
      display: flex;
    }

    .modal-content {
      background: var(--white);
      padding: 24px;
      border-radius: var(--radius-lg);
      text-align: center;
      width: 100%;
      max-width: 360px;
      box-shadow: var(--shadow-xl);
      border: 1px solid var(--gray-200);
      position: relative;
      overflow: hidden;
      transform: translateY(-20px);
      transition: var(--transition-normal);
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
      color: var(--gray-800);
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 12px;
    }

    .modal-content p {
      color: var(--gray-600);
      font-size: 14px;
      margin-bottom: 24px;
      line-height: 1.4;
    }

    .modal-buttons {
      display: flex;
      gap: 12px;
      justify-content: center;
    }

    .modal-buttons button {
      padding: 10px 20px;
      border: none;
      border-radius: var(--radius-md);
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: var(--transition-normal);
      min-width: 80px;
      box-shadow: var(--shadow-sm);
      font-family: inherit;
    }

    .modal-buttons button:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-cancel {
      background: var(--gray-200);
      color: var(--gray-700);
      border: 1px solid var(--gray-300);
    }

    .btn-cancel:hover {
      background: var(--gray-300);
    }

    .btn-primary { /* For general primary buttons in modals/forms */
        background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        color: var(--white);
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
    }

    .btn-confirm-logout { /* Specific for logout - Changed to danger style */
      background: var(--danger);
      color: var(--white);
    }

    .btn-confirm-logout:hover {
      background: var(--action-reject-dark);
    }

    /* Form Specific Styles for Change Password (Adjusted for main-content placement) */
    .password-form-card {
        background: var(--white);
        border-radius: var(--radius-lg);
        padding: 24px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        max-width: 500px;
        margin: 24px auto; /* Center the form within the main content */
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-group label {
        display: block;
        font-size: 12px;
        color: var(--gray-700);
        margin-bottom: 6px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-group input[type="password"] {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--gray-300);
        border-radius: var(--radius-md);
        font-size: 14px;
        color: var(--gray-800);
        background-color: var(--gray-100);
        transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    }

    .form-group input[type="password"]:focus {
        border-color: var(--khaki-primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(189, 183, 107, 0.2);
    }

    .form-actions {
        padding-top: 16px;
        border-top: 1px solid var(--gray-200);
        margin-top: 24px;
        text-align: right;
        display: flex; /* Make buttons align */
        flex-direction: row-reverse; /* Reverse order to put submit on right */
        gap: 12px; /* Space between buttons */
    }

    .btn-submit {
        padding: 12px 20px;
        border: none;
        border-radius: var(--radius-md);
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        transition: var(--transition-normal);
        background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        color: var(--white);
        box-shadow: var(--shadow-sm);
        flex-grow: 1; /* Allow button to grow */
    }

    .btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
        background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
    }

    .btn-back { /* Style for the "Back to Dashboard" button */
        background: var(--gray-200);
        color: var(--gray-700);
        border: 1px solid var(--gray-300);
        padding: 12px 20px;
        border-radius: var(--radius-md);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: var(--transition-normal);
        flex-grow: 1; /* Allow button to grow */
        box-shadow: var(--shadow-sm);
        text-decoration: none;
        display: flex; /* Use flex to center content for anchor tags */
        align-items: center;
        justify-content: center;
    }

    .btn-back:hover {
        background: var(--gray-300);
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    /* RESPONSIVE DESIGN (Copied from T_ChangePassword.php, adjusted for M_ChangePassword specifics) */
    @media (max-width: 1024px) {
      .sidebar {
        width: 200px;
      }

      .main-content {
        padding: 20px;
        margin-left: 200px; /* Adjusted for smaller sidebar */
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
        position: static; /* Revert to static */
        height: auto; /* Revert height */
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

      .password-form-card {
        padding: 20px;
        margin: 16px auto;
      }

      .form-actions {
        flex-direction: column; /* Stack buttons on smaller screens */
      }
    }

    @media (max-width: 480px) {
      .main-content {
        padding: 12px;
      }

      .content-header h1 {
        font-size: 22px;
      }

      .password-form-card {
        padding: 16px;
      }
    }

    /* COMPACT ANIMATIONS (Copied from T_ChangePassword.php) */
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

    /* FOCUS STYLES FOR ACCESSIBILITY (Copied from T_ChangePassword.php) */
    .form-group input[type="password"]:focus,
    .btn-submit:focus,
    .btn-back:focus, /* Added for M_ChangePassword specific back button */
    .sidebar nav li a:focus,
    .modal-buttons button:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES (Copied from T_ChangePassword.php) */
    .btn-submit:disabled,
    .btn-back:disabled, /* Added for M_ChangePassword specific back button */
    .modal-buttons button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* PRINT STYLES (Copied from T_ChangePassword.php) */
    @media print {
      .sidebar,
      .modal {
        display: none;
      }

      .main-content {
        padding: 0;
      }

      .password-form-card {
        box-shadow: none;
        border: 1px solid var(--gray-300);
        break-inside: avoid;
      }

      .app-message { /* Ensure messages are visible in print */
        display: block;
        opacity: 1;
        transform: translateY(0);
        background: none !important;
        color: #333 !important;
        border: 1px solid #ccc !important;
        page-break-inside: avoid;
      }

      .btn-submit, .btn-back {
        display: none; /* Hide buttons in print */
      }
    }
    /* COMPACT PROFESSIONAL KHAKI THEME CSS - END */
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
        <h1>Change Password</h1>
        <p>Update your account password securely.</p>
      </div>

      <div id="appMessageContainer">
        <?php if ($flash_message): // Display flash message from PHP ?>
            <div class='app-message <?php echo $flash_message_type; ?> show'><?php echo $flash_message; ?></div>
        <?php endif; ?>
      </div>

      <div class="password-form-card">
        <form id="changePasswordForm" method="POST" action="M_ChangePassword.php">
          <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
          </div>
          <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="confirm_new_password">Confirm New Password</label>
            <input type="password" id="confirm_new_password" name="confirm_new_password" required autocomplete="new-password">
          </div>
          <div class="form-actions">
            <button type="submit" class="btn-submit">Change Password</button>
            <a href="M_Dashboard.php" class="btn-back">Back to Dashboard</a>
          </div>
        </form>
      </div>

    </main>
  </div>

  <div id="logoutModal" class="modal">
    <div class="modal-content">
      <h3>Confirm Logout</h3>
      <p>Are you sure you want to log out?</p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="cancelLogout">Cancel</button>
        <button class="btn-confirm-logout" id="confirmLogout">Log out</button>
      </div>
    </div>
  </div>

  <script>
    const logoutLink = document.getElementById('logoutLink');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogoutBtn = document.getElementById('confirmLogout');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    const appMessageContainer = document.getElementById('appMessageContainer');

    // Function to display app-level messages (re-used from T_Pending.php)
    function showAppFeedback(messageText, type) {
        appMessageContainer.innerHTML = ''; // Clear previous messages
        const messageDiv = document.createElement('div');
        messageDiv.className = `app-message ${type} show`;
        messageDiv.innerHTML = messageText; // Use innerHTML to allow <br> tags
        appMessageContainer.appendChild(messageDiv);

        setTimeout(() => {
            messageDiv.classList.remove('show');
            setTimeout(() => { // Allow fade out animation to complete
                 if(messageDiv.parentNode) appMessageContainer.removeChild(messageDiv);
            }, 400);
        }, 3000); // Message visible for 3 seconds
    }

    // Logout Logic (re-used from T_Pending.php)
    if (logoutLink) {
        logoutLink.addEventListener('click', (e) => {
            e.preventDefault();
            if (logoutModal) logoutModal.classList.add('show');
        });
    }
    if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener('click', () => {
            document.body.style.transition = 'opacity 0.25s ease';
            document.body.style.opacity = '0';
            setTimeout(() => {
                window.location.href = 'logout.php'; // Redirect to logout page
            }, 400);
        });
    }
    if (cancelLogoutBtn) {
        cancelLogoutBtn.addEventListener('click', () => {
            if (logoutModal) logoutModal.classList.remove('show');
        });
    }

    // Close modals when clicking outside
    if (logoutModal) {
        logoutModal.addEventListener('click', function(e) {
            if (e.target === logoutModal) {
                logoutModal.classList.remove('show');
            }
        });
    }

    // Client-side form validation for password change
    document.getElementById('changePasswordForm').addEventListener('submit', function(event) {
        const currentPasswordInput = document.getElementById('current_password');
        const newPasswordInput = document.getElementById('new_password');
        const confirmNewPasswordInput = document.getElementById('confirm_new_password');

        const currentPassword = currentPasswordInput.value;
        const newPassword = newPasswordInput.value;
        const confirmNewPassword = confirmNewPasswordInput.value;

        let isValid = true;
        let messages = [];

        // Reset styling for all inputs
        currentPasswordInput.style.borderColor = '';
        newPasswordInput.style.borderColor = '';
        confirmNewPasswordInput.style.borderColor = '';
        currentPasswordInput.style.boxShadow = '';
        newPasswordInput.style.boxShadow = '';
        confirmNewPasswordInput.style.boxShadow = '';


        if (currentPassword.trim() === '') {
            messages.push('Current Password is required.');
            currentPasswordInput.style.borderColor = 'var(--danger)';
            isValid = false;
        }
        if (newPassword.trim() === '') {
            messages.push('New Password is required.');
            newPasswordInput.style.borderColor = 'var(--danger)';
            isValid = false;
        }
        if (confirmNewPassword.trim() === '') {
            messages.push('Confirm New Password is required.');
            confirmNewPasswordInput.style.borderColor = 'var(--danger)';
            isValid = false;
        }
        if (newPassword !== confirmNewPassword) {
            messages.push('New Passwords do not match.');
            newPasswordInput.style.borderColor = 'var(--danger)';
            confirmNewPasswordInput.style.borderColor = 'var(--danger)';
            isValid = false;
        }
        // Password complexity validation: at least one lowercase, one uppercase, one digit, one special char
        const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/;
        if (newPassword.trim() !== '' && !passwordRegex.test(newPassword)) {
            messages.push('New Password must be strong: at least one lowercase, one uppercase, one number, and one special character.');
            newPasswordInput.style.borderColor = 'var(--danger)';
            isValid = false;
        }
        if (newPassword.trim() !== '' && newPassword.length < 8) {
            messages.push('New Password must be at least 8 characters long.');
            newPasswordInput.style.borderColor = 'var(--danger)';
            isValid = false;
        }

        if (!isValid) {
            showAppFeedback(messages.join('<br>'), 'danger');
            event.preventDefault(); // Stop form submission
        }
    });

    // Sidebar active link highlighting
    document.addEventListener('DOMContentLoaded', () => {
        const currentPage = window.location.pathname.split("/").pop(); // Get filename
        document.querySelectorAll('.sidebar nav li a').forEach(link => {
            const linkPage = link.getAttribute('href');
            if (linkPage === currentPage) {
                link.closest('li').classList.add('active');
            } else {
                link.closest('li').classList.remove('active');
            }
        });

        // Check for PHP flash messages on initial load and display them
        const phpFlashMessageText = '<?php echo $flash_message; ?>';
        const phpFlashMessageType = '<?php echo $flash_message_type; ?>';

        if (phpFlashMessageText !== '') {
            // Use the showAppFeedback function to display PHP messages
            showAppFeedback(phpFlashMessageText, phpFlashMessageType);
        }
    });
  </script>
</body>
</html>