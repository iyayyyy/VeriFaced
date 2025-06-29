<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Enable robust error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include your database connection file. This file MUST create a $conn variable
// that is a valid MySQLi database connection object.
require_once 'db_connect.php';

// Function to safely output HTML to prevent Cross-Site Scripting (XSS) vulnerabilities.
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true || !isset($_SESSION['tenant_id'])) {
    $_SESSION['flash_message'] = "Please log in to change your password.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: login.php"); // Redirect to tenant login page
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

// Initialize variables for flash messages
$flash_message = '';
$flash_message_type = '';

// Fetch Pending count for badge (assuming this is needed for the sidebar badge)
// This part was missing from the provided T_ChangePassword.php, adding it for consistency
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
        error_log("Error fetching pending count for T_ChangePassword: " . $e->getMessage());
    } finally {
        if ($stmt_pending_count) {
            $stmt_pending_count->close();
        }
    }
}


// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $currentPassword = $_POST['currentPassword'] ?? '';
    $newPassword = $_POST['newPassword'] ?? '';
    $confirmNewPassword = $_POST['confirmNewPassword'] ?? '';

    $errors = [];

    // Validation
    if (empty($currentPassword)) {
        $errors[] = "Current Password is required.";
    }
    if (empty($newPassword)) {
        $errors[] = "New Password is required.";
    }
    if ($newPassword !== $confirmNewPassword) {
        $errors[] = "New Passwords do not match.";
    }
    // Password complexity validation
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/', $newPassword)) {
        $errors[] = "New Password must contain at least one lowercase letter, one uppercase letter, one number, and one special character.";
    }
    if (strlen($newPassword) < 8) {
        $errors[] = "New Password must be at least 8 characters long.";
    }


    if (empty($errors)) {
        $stmt_check_password = null;
        $stmt_update_password = null;
        try {
            // 1. Fetch current password hash from the database
            $sql_get_hash = "SELECT password_hash FROM tenants WHERE tenant_id = ?";
            $stmt_check_password = $conn->prepare($sql_get_hash);
            if ($stmt_check_password) {
                $stmt_check_password->bind_param("i", $logged_in_tenant_id);
                $stmt_check_password->execute();
                $result = $stmt_check_password->get_result();
                $tenant = $result->fetch_assoc();

                if ($tenant) {
                    // 2. Verify current password
                    if (password_verify($currentPassword, $tenant['password_hash'])) {
                        // 3. Hash the new password
                        $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                        // 4. Update the database with the new password hash
                        $sql_update_password = "UPDATE tenants SET password_hash = ? WHERE tenant_id = ?";
                        $stmt_update_password = $conn->prepare($sql_update_password);
                        if ($stmt_update_password) {
                            $stmt_update_password->bind_param("si", $hashedNewPassword, $logged_in_tenant_id);
                            if ($stmt_update_password->execute()) {
                                $_SESSION['flash_message'] = "Your password has been changed successfully!";
                                $_SESSION['flash_message_type'] = "success";
                                // Redirect back to profile or dashboard after successful change
                                header("Location: T_UnitProfile.php");
                                exit();
                            } else {
                                $errors[] = "Error updating password: " . $stmt_update_password->error;
                            }
                        } else {
                            $errors[] = "Database error: Could not prepare update statement.";
                        }
                    } else {
                        $errors[] = "Current Password is incorrect.";
                    }
                } else {
                    $errors[] = "Tenant not found.";
                }
            } else {
                $errors[] = "Database error: Could not prepare password retrieval statement.";
            }
        } catch (Throwable $e) {
            error_log("Password change error: " . $e->getMessage());
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
    // Redirect to self to display messages and prevent form resubmission
    header("Location: T_ChangePassword.php");
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
  <title>Verifaced: Change Password</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* Add your existing CSS from T_Pending.php here, but remove .active class for pending link */
    /* You might want to remove some specific styles like .visitor-card if they are not relevant to this page */
    /* Specifically, keep .container, .sidebar, .main-content, .content-header, .app-message, .modal, and all form-related styles */
    /* COMPACT PROFESSIONAL KHAKI THEME CSS - START (Copy from T_Pending.php) */
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
      /* Changed to red for consistency with UnitProfile remove button */
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
      /* REMOVED: display: flex; */
      /* REMOVED: min-height: 100vh; */
    }

    /* COMPACT SIDEBAR STYLES */
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
      background: var(--button-neutral-bg); /* Changed to neutral button style */
      color: var(--button-neutral-text); /* Changed to neutral button style */
      text-decoration: none;
      border-radius: var(--radius-md);
      text-align: center;
      font-weight: 600;
      font-size: 13px;
      transition: var(--transition-normal);
      border: 1px solid var(--button-neutral-border); /* Changed to neutral button style */
      box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Changed to neutral button style */
    }

    .logout:hover {
      background: var(--button-neutral-hover-bg); /* Changed to neutral button style */
      color: var(--button-neutral-hover-text); /* Changed to neutral button style */
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.1); /* Changed to neutral button style */
    }

    /* COMPACT MAIN CONTENT STYLES */
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

    /* COMPACT MESSAGE SYSTEM */
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

    /* COMPACT MODALS */
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
      background: var(--action-reject-dark); /* Use darker danger color */
    }

    /* Form Specific Styles for Change Password */
    .password-form-card {
        background: var(--white);
        border-radius: var(--radius-lg);
        padding: 24px;
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        max-width: 500px;
        margin: 24px auto; /* Center the form */
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
        box-shadow: 0 0 0 3px rgba(var(--khaki-primary), 0.2);
    }

    .form-actions {
        padding-top: 16px;
        border-top: 1px solid var(--gray-200);
        margin-top: 24px;
        text-align: right;
    }

    .btn-submit {
        padding: 10px 20px;
        border: none;
        border-radius: var(--radius-md);
        font-weight: 600;
        font-size: 13px;
        cursor: pointer;
        transition: var(--transition-normal);
        background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        color: var(--white);
        box-shadow: var(--shadow-sm);
    }

    .btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
        background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
    }

    /* RESPONSIVE DESIGN */
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

    /* COMPACT ANIMATIONS */
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

    /* FOCUS STYLES FOR ACCESSIBILITY */
    .sidebar nav li a:focus,
    .btn-submit:focus,
    .modal-buttons button:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES */
    .btn-submit:disabled,
    .modal-buttons button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* PRINT STYLES */
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
          <li><a href="T_UnitProfile.php"><span></span> Unit Profile</a></li>
          <li><a href="T_VisitorsList.php"><span></span> Visitors List</a></li>
          <li><a href="T_Pending.php"><span></span> Pending <span class="badge" id="pendingBadge"><?php echo $pending_badge_count; ?></span></a></li>
          <li><a href="T_MemberRegistration.php"><span></span> Member Registration</a></li>
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
        <form id="changePasswordForm" method="POST" action="T_ChangePassword.php">
          <div class="form-group">
            <label for="currentPassword">Current Password</label>
            <input type="password" id="currentPassword" name="currentPassword" required autocomplete="current-password">
          </div>
          <div class="form-group">
            <label for="newPassword">New Password</label>
            <input type="password" id="newPassword" name="newPassword" required autocomplete="new-password">
          </div>
          <div class="form-group">
            <label for="confirmNewPassword">Confirm New Password</label>
            <input type="password" id="confirmNewPassword" name="confirmNewPassword" required autocomplete="new-password">
          </div>
          <div class="form-actions">
            <button type="submit" class="btn-submit">Change Password</button>
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
        messageDiv.textContent = messageText;
        appMessageContainer.appendChild(messageDiv);

        setTimeout(() => {
            messageDiv.classList.remove('show');
            setTimeout(() => { // Allow fade out animation
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
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmNewPassword = document.getElementById('confirmNewPassword').value;

        let isValid = true;
        let messages = [];

        // Reset styling
        document.getElementById('currentPassword').style.borderColor = '';
        document.getElementById('newPassword').style.borderColor = '';
        document.getElementById('confirmNewPassword').style.borderColor = '';

        if (!currentPassword) {
            messages.push('Current Password is required.');
            document.getElementById('currentPassword').style.borderColor = 'red';
            isValid = false;
        }
        if (!newPassword) {
            messages.push('New Password is required.');
            document.getElementById('newPassword').style.borderColor = 'red';
            isValid = false;
        }
        if (newPassword !== confirmNewPassword) {
            messages.push('New Passwords do not match.');
            document.getElementById('confirmNewPassword').style.borderColor = 'red';
            isValid = false;
        }
        // Password complexity validation
        const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/; // At least one lowercase, one uppercase, one digit, one special char
        if (!passwordRegex.test(newPassword)) {
            messages.push('New Password must be strong: at least one lowercase, one uppercase, one number, and one special character.');
            document.getElementById('newPassword').style.borderColor = 'red';
            isValid = false;
        }
        if (newPassword.length < 8) {
            messages.push('New Password must be at least 8 characters long.');
            document.getElementById('newPassword').style.borderColor = 'red';
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

        // Auto-hide flash messages after 3 seconds
        const flashMessage = document.querySelector('.app-message.show');
        if (flashMessage) {
          setTimeout(() => {
            flashMessage.classList.remove('show');
          }, 3000);
        }
    });

    // Update the pending badge count (if you have an AJAX call to get it)
    // For now, this is just a placeholder, you'd need to fetch this count via AJAX
    function updatePendingBadgeCount() {
        // This function would typically make an AJAX request to fetch the current pending count
        // and then update the badge. Since we don't have that endpoint, it will stay 0.
        // Example:
        /*
        fetch('get_pending_count.php') // You'd need to create this PHP file
            .then(response => response.json())
            .then(data => {
                const pendingBadge = document.getElementById('pendingBadge');
                if (pendingBadge) {
                    pendingBadge.textContent = data.count;
                }
            })
            .catch(error => console.error('Error fetching pending count:', error));
        */
        const pendingBadge = document.getElementById('pendingBadge');
        if (pendingBadge) {
            // The PHP-fetched count for initial load is already echoed in the HTML.
            // This JS function would be for dynamic updates without page reload.
            // For now, it simply re-reads the value set by PHP.
            // If you implement a separate AJAX endpoint for count, uncomment fetch logic.
        }
    }
    updatePendingBadgeCount(); // Call on page load
  </script>
</body>
</html>