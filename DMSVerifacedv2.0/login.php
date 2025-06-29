<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Include your database connection file. This file MUST create a $conn variable
// that is a valid MySQLi database connection object.
require_once 'db_connect.php';

// Function to safely output HTML to prevent Cross-Site Scripting (XSS) vulnerabilities.
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Initialize variables for flash messages
$flash_message = '';
$flash_message_type = '';

// Check if a flash message is set in the session and retrieve it.
if (isset($_SESSION['flash_message'])) {
    $flash_message = e($_SESSION['flash_message']);
    $flash_message_type = e($_SESSION['flash_message_type'] ?? 'info');
    // Clear the flash message from the session after retrieving it.
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Check if the user is already logged in (either admin, tenant, or added member) and redirect if so.
// This prevents logged-in users from seeing the login form.
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: index.php"); // Redirect admins to admin dashboard
    exit();
}
if (isset($_SESSION['tenant_logged_in']) && $_SESSION['tenant_logged_in'] === true) {
    header("Location: T_UnitProfile.php"); // Redirect tenants to their dashboard
    exit();
}
// NEW: Check for added_member login and redirect
if (isset($_SESSION['added_member_logged_in']) && $_SESSION['added_member_logged_in'] === true) {
    header("Location: M_Dashboard.php"); // Redirect added members to their dashboard (create this file!)
    exit();
}


// Handle form submission (this section remains primarily for Tenant login with optional Face Rec)
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Flag to check if this submission originated from a successful client-side face recognition
    $face_login_success = filter_var(($_POST['face_login_success'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);
    $recognized_tenant_id = trim($_POST['recognized_tenant_id'] ?? '');

    // 1. Validate and Sanitize Input
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);

    $stmt = null;
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $tenant = null; // Initialize tenant variable

            // Always fetch the tenant by email first, as both paths (face or password) need it
            $sql = "SELECT tenant_id, email, password_hash FROM tenants WHERE email = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $tenant = $result->fetch_assoc();

                    $authentication_successful = false; // Flag for overall success

                    if ($face_login_success && !empty($recognized_tenant_id)) {
                        // Scenario 1: Form submitted after successful client-side face recognition
                        // FIRST CHECK: Does the recognized tenant ID match the fetched tenant's ID?
                        if ($tenant['tenant_id'] == $recognized_tenant_id) {
                            // SECOND CHECK: Verify the password (even if face recognized)
                            if (password_verify($password, $tenant['password_hash'])) {
                                $authentication_successful = true;
                                $_SESSION['flash_message'] = "Welcome, " . htmlspecialchars($tenant['email']) . "! You have logged in successfully with face recognition.";
                                $_SESSION['flash_message_type'] = "success";
                            } else {
                                $_SESSION['flash_message'] = "Face recognized, but invalid password. Please try again.";
                                $_SESSION['flash_message_type'] = "danger";
                            }
                        } else {
                            $_SESSION['flash_message'] = "Face recognized, but it does not match the provided email account. Please try again.";
                            $_SESSION['flash_message_type'] = "danger";
                        }
                    } else {
                        // Scenario 2: Regular email/password login attempt (if face login wasn't used or failed client-side)
                        // Verify the password
                        if (password_verify($password, $tenant['password_hash'])) {
                            $authentication_successful = true;
                            $_SESSION['flash_message'] = "Welcome, " . htmlspecialchars($tenant['email']) . "! You have logged in successfully.";
                            $_SESSION['flash_message_type'] = "success";
                        } else {
                            // Password does not match
                            $_SESSION['flash_message'] = "Invalid email or password. Please try again.";
                            $_SESSION['flash_message_type'] = "danger";
                        }
                    }

                    if ($authentication_successful) {
                        $_SESSION['tenant_logged_in'] = true;
                        $_SESSION['tenant_id'] = $tenant['tenant_id'];
                        $_SESSION['tenant_email'] = $tenant['email'];
                        header("Location: T_UnitProfile.php");
                        exit();
                    }

                } else {
                    // No tenant found with that email
                    $_SESSION['flash_message'] = "Invalid email or password. Please try again.";
                    $_SESSION['flash_message_type'] = "danger";
                }
            } else {
                error_log("Failed to prepare statement for tenant login: " . $conn->error);
                $_SESSION['flash_message'] = "Database error during login. Please try again later.";
                $_SESSION['flash_message_type'] = "danger";
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Please try again later.";
            $_SESSION['flash_message_type'] = "danger";
            error_log("Database connection variable \$conn is not set or not a mysqli object in login.php.");
        }
    } catch (Throwable $e) {
        error_log("Tenant login error: " . $e->getMessage());
        $_SESSION['flash_message'] = "An unexpected error occurred. Please try again later.";
        $_SESSION['flash_message_type'] = "danger";
    } finally {
        if ($stmt) {
            $stmt->close();
        }
    }
    // Redirect to self to display flash messages (Post/Redirect/Get pattern)
    // This redirect will happen if regular login fails or face login attempt fails.
    header("Location: login.php");
    exit();
}

// PHP to fetch tenant data for Unit_1, Unit_2, and Unit_3
$allTenantFaceData = [];
if (isset($conn) && $conn instanceof mysqli) {
    // Modified SQL query to fetch tenants associated with 'Unit 1', 'Unit 2', or 'Unit 3'
    $sql_fetch_tenant_info = "SELECT t.tenant_id, t.first_name, t.last_name, u.unit_number
                              FROM tenants t
                              JOIN units u ON t.unit_id = u.unit_id
                              WHERE u.unit_number IN ('Unit 1', 'Unit 2', 'Unit 3')
                              AND t.known_face_path_1 IS NOT NULL AND t.known_face_path_1 != ''";
    $result_tenant_info = $conn->query($sql_fetch_tenant_info);

    if ($result_tenant_info) {
        if ($result_tenant_info->num_rows > 0) {
            while ($row = $result_tenant_info->fetch_assoc()) {
                // Ensure the unit_number is correctly formatted for folder names (Unit 1 -> Unit_1)
                $folder_unit_name = str_replace(' ', '_', $row['unit_number']);
                $allTenantFaceData[] = [
                    'tenant_id' => $row['tenant_id'],
                    'full_name' => $row['first_name'] . ' ' . $row['last_name'],
                    'unit_name' => $folder_unit_name // Use the formatted unit name for JS mapping
                ];
            }
        } else {
            error_log("No tenants found in 'Unit 1', 'Unit 2', or 'Unit 3' with known face paths.");
        }
        $result_tenant_info->free();
    } else {
        error_log("Error fetching tenant info for specified units face data: " . $conn->error);
    }
} else {
    error_log("Database connection not available for fetching tenant info in login.php (GET).");
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Your CSS styles (copied from your Login.html file) */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%); /* Interchanged colors */
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            display: flex;
            width: 90%;
            max-width: 1200px;
            min-height: 80vh;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            position: relative;
        }

        .header {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%); /* Interchanged colors */
            color: white;
            padding: 20px 40px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 24px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3); /* Based on #6B5B4F */
            border-radius: 20px 20px 0 0;
            height: 80px; /* Fixed height for header */
        }

        .logo-circle {
            width: 40px;
            height: 40px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
            font-weight: bold;
        }

        .logo-circle::before {
            content: "V";
        }

        .left-section {
            flex: 1;
            background: rgba(245, 245, 220, 0.5); /* Retaining a light khaki/beige */
            padding: 100px 40px 40px; /* Adjust padding for header */
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start; /* Align text to the left */
        }

        .left-section h1 {
            font-size: 48px; /* Slightly reduced for better fit */
            font-weight: bold;
            color: #333;
            line-height: 1.1;
            margin-bottom: 20px;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .left-section p {
            font-size: 18px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 40px;
        }

        .contact-info {
            display: flex;
            flex-direction: column;
            gap: 15px; /* Increased gap */
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 15px; /* Increased gap */
            color: #666;
            font-size: 16px;
            font-weight: 500;
        }

        .contact-icon {
            width: 28px; /* Larger icon */
            height: 28px; /* Larger icon */
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%); /* Interchanged colors */
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            box-shadow: 0 2px 10px rgba(107, 91, 79, 0.2); /* Based on #6B5B4F */
        }

        .right-section {
            flex: 1;
            padding: 100px 40px 40px; /* Adjust padding for header */
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-form {
            background: #ffffff;
            border: 1px solid rgba(221, 221, 221, 0.5); /* Lighter border */
            border-radius: 12px; /* More rounded corners */
            padding: 40px; /* Increased padding */
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            margin-bottom: 30px; /* Increased margin */
            text-align: center;
        }

        .form-header h2 {
            font-size: 24px; /* Larger font size */
            font-weight: 700; /* Bolder */
            margin-bottom: 10px;
            color: #333;
        }

        .form-header p {
            font-size: 16px; /* Larger font size */
            color: #777;
        }

        .form-group {
            margin-bottom: 20px; /* Increased margin */
        }

        .form-group label {
            display: block;
            margin-bottom: 8px; /* Increased margin */
            font-size: 16px;
            color: #555;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 14px; /* Increased padding */
            border: 1px solid #ddd;
            border-radius: 8px; /* More rounded */
            font-size: 16px;
            background: #fdfdfd;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #6B5B4F; /* Using #6B5B4F */
            box-shadow: 0 0 0 3px rgba(107, 91, 79, 0.2); /* Based on #6B5B4F */
        }

        .form-group input::placeholder {
            color: #aaa;
        }

        .sign-in-btn {
            width: 100%;
            border: none;
            padding: 14px; /* Increased padding */
            border-radius: 8px; /* More rounded */
            font-size: 16px; /* Larger font size */
            font-weight: 600; /* Bolder */
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .sign-in-btn {
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%); /* Interchanged colors */
            color: white;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3); /* Based on #6B5B4F */
        }

        .sign-in-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107, 91, 79, 0.4); /* Based on #6B5B4F */
        }

        /* NEW STYLES FOR BUTTON GROUP */
        .button-group {
            display: flex;
            gap: 15px; /* Space between buttons */
            margin-bottom: 20px; /* Space below the button group */
        }

        .button-group .action-button {
            flex: 1; /* Allow buttons to take equal width */
            border: 2px solid #6B5B4F; /* Using #6B5B4F */
            background: transparent;
            color: #6B5B4F;
            padding: 14px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center; /* Ensure text is centered within the button */
        }

        .button-group .action-button:hover {
            background: #6B5B4F;
            color: white;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3);
            transform: translateY(-2px);
        }
        /* END NEW STYLES */


        .admin-link {
            text-align: center;
        }

        .admin-link a {
            color: #6B5B4F; /* Using #6B5B4F */
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s;
        }

        .admin-link a:hover {
            text-decoration: underline;
            color: #8B7D6B; /* Using #8B7D6B */
        }

        .flash-message {
            padding: 15px 20px;
            border-radius: 6px;
            margin: 0 0 20px 0;
            color: #fff;
            font-weight: 500;
            opacity: 1;
            transition: opacity 0.5s ease-out;
            border: 1px solid transparent;
            box-sizing: border-box;
            max-height: 100px; /* Initial max-height for smooth transition */
            overflow: hidden; /* Hide overflowing content during transition */
        }

        .flash-message.success {
            background-color: #4CAF50; /* Changed to green */
            border-color: #4CAF50; /* Changed to green */
            color: white;
        }

        .flash-message.danger {
            background-color: #DC3545;
            border-color: #C82333;
            color: white;
        }

        .flash-message.warning {
            background-color: #FFC107;
            border-color: #E0A800;
            color: #212529;
        }

        .flash-message.info {
            background-color: #0DCAF0;
            border-color: #0AAACC;
            color: white;
        }

        /* MODAL STYLES (Copied from visitorform.php, adapted for login) */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal.show {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: #FFFFFF;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 12px 32px rgba(139, 134, 78, 0.25);
            width: 90%;
            max-width: 600px;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            position: relative;
            text-align: center;
            border: 1px solid #E9ECEF;
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            margin-bottom: 20px;
            border-bottom: 1px solid #E9ECEF;
            padding-bottom: 15px;
        }

        .modal-header h3 {
            font-size: 24px;
            color: #343A40;
            font-weight: 700;
        }

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
            background-color: #212529;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: inset 0 0 10px rgba(0,0,0,0.3);
            border: 2px solid #495057;
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
            border-radius: 12px;
        }
        
        .camera-feed-container canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border-radius: 12px;
        }
        
        #faceScanMessage {
            font-size: 14px;
            color: #6C757D;
            min-height: 20px;
            text-align: center;
        }

        .modal-actions {
            display: flex;
            justify-content: center;
            gap: 15px;
            width: 100%;
        }

        .modal-actions .btn {
            flex-grow: 1;
            /* Base styles for modal buttons - inheriting from common styles or defining new ones */
            padding: 14px 20px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            line-height: 1;
        }

        /* Style for Capture Photo button (primary action in modal) */
        #capturePhotoBtn {
            background: linear-gradient(135deg, #C3B091 0%, #B8A082 100%); /* Khaki gradient */
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(195, 176, 145, 0.4);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            white-space: nowrap;
        }

        #capturePhotoBtn:hover {
            background: linear-gradient(135deg, #B8A082 0%, #AD9573 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(195, 176, 145, 0.5);
        }

        #capturePhotoBtn:disabled {
            background: #e0e0e0;
            color: #999;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        /* Style for Retake Photo button (secondary action, similar to action-button but distinct) */
        #retakePhotoBtn {
            background: transparent;
            border: 2px solid #C3B091; /* Khaki border */
            color: #8B7D6B;
            box-shadow: none;
            white-space: nowrap;
        }

        #retakePhotoBtn:hover {
            background: #C3B091;
            color: white;
            box-shadow: 0 4px 15px rgba(195, 176, 145, 0.4);
            transform: translateY(-2px);
        }

        /* Style for Cancel/Close button (tertiary action) */
        #closeModalBtn {
            background: linear-gradient(135deg, #A68B5B 0%, #9B8050 100%); /* Darker khaki for cancel */
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(166, 139, 91, 0.4);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
            white-space: nowrap;
        }

        #closeModalBtn:hover {
            background: linear-gradient(135deg, #9B8050 0%, #907545 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(166, 139, 91, 0.5);
        }


        .recognition-result {
            margin-top: 15px;
            padding: 10px 15px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 15px;
            text-align: center;
            min-height: 20px;
            width: 100%;
        }

        .recognition-result.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28A745;
        }

        .recognition-result.danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: #DC3545;
        }
        
        .recognition-result.info {
            background-color: rgba(23, 162, 184, 0.1);
            color: #17A2B8;
        }


        @media (max-width: 992px) {
            .container {
                flex-direction: column;
                width: 95%;
                min-height: auto;
                height: auto;
            }

            .header {
                position: relative; /* Make header part of flow on small screens */
                border-radius: 20px 20px 0 0;
                height: auto;
                padding: 15px 20px;
                font-size: 20px;
            }

            .left-section, .right-section {
                padding: 30px 20px; /* Reduced padding */
                text-align: center; /* Center align text on small screens */
                align-items: center; /* Center align items */
            }

            .left-section h1 {
                font-size: 36px;
                margin-bottom: 15px;
            }

            .left-section p {
                font-size: 16px;
                margin-bottom: 30px;
            }
            
            .contact-info {
                align-items: center;
            }

            .button-group {
                flex-direction: column; /* Stack buttons on small screens */
                gap: 10px;
            }
        }

        @media (max-width: 576px) {
            .login-form {
                padding: 25px; /* Further reduced padding */
            }

            .form-header h2 {
                font-size: 20px;
            }

            .form-header p {
                font-size: 14px;
            }

            .sign-in-btn, .action-button {
                font-size: 14px;
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo-circle"></div>
            Verifaced
        </div>

        <div class="left-section">
            <h1>Welcome to Verifaced</h1>
            <p>Secure your unit by accessing Verifaced. Register visitors and members for secured experience.</p>
            
            <div class="contact-info">
                <div class="contact-item">
                    <div class="contact-icon">📞</div>
                    <span>+63 123 456 7890</span>
                </div>
                <div class="contact-item">
                    <div class="contact-icon">✉</div>
                    <span>admin@verifaced.com</span>
                </div>
            </div>
        </div>

        <div class="right-section">
            <div class="login-form">
                <div class="form-header">
                    <h2>Sign In to Your Account</h2>
                </div>

                <?php
                // Display flash messages
                if ($flash_message) {
                    echo "<div class='flash-message {$flash_message_type}'>{$flash_message}</div>";
                }
                ?>

                <form id="loginForm" method="POST" action="login.php">
                    <div class="form-group">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" placeholder="Enter your email">
                    </div>

                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password">
                    </div>

                    <button type="submit" class="sign-in-btn" id="signInBtn">Sign In</button>
                    
                    <div class="button-group">
                        <button type="button" class="action-button" onclick="window.location.href='memberlogin.php'">Sign In as Member</button>
                        <button type="button" class="action-button" onclick="window.location.href='visitorform.php'">Sign In as Visitor</button>
                    </div>

                    <input type="hidden" id="faceLoginSuccess" name="face_login_success" value="false">
                    <input type="hidden" id="recognizedTenantId" name="recognized_tenant_id" value="">
                </form>

                <div class="admin-link">
                    <a href="adminlogin.php">Log In as Admin</a>
                </div>
            </div>
        </div>
    </div>

    <div id="facialRecognitionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Face Login Verification</h3>
            </div>
            <div class="modal-body">
                <div class="camera-feed-container">
                    <video id="cameraFeedVideo" autoplay playsinline style="display:none;"></video> 
                    <canvas id="captureCanvas" style="display:none;"></canvas>
                    <canvas id="faceBoundingBoxCanvas"></canvas>
                </div>
                <p id="faceScanMessage">Please allow camera access and position your face.</p>
                <div id="recognitionResult" class="recognition-result"></div>
                
                <div class="modal-actions">
                    <button type="button" class="btn" id="capturePhotoBtn">Capture Photo</button>
                    <button type="button" class="btn" id="retakePhotoBtn" style="display:none;">Retake Photo</button>
                    <button type="button" class="btn" id="closeModalBtn">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js"></script>
    <script>
        // Data passed from PHP for tenants associated with 'Unit 1' or 'Unit 2'
        const ALL_TENANT_DATA = <?php echo json_encode($allTenantFaceData); ?>;

        function showFlashMessage(message, type) {
            const flashMessageDiv = document.querySelector('.flash-message');
            if (flashMessageDiv) {
                flashMessageDiv.remove(); // Remove existing message
            }

            const newFlashMessageDiv = document.createElement('div');
            newFlashMessageDiv.className = `flash-message ${type}`;
            newFlashMessageDiv.innerHTML = message; // Use innerHTML to allow for more complex messages like <br>

            const formHeader = document.querySelector('.form-header');
            formHeader.after(newFlashMessageDiv);

            setTimeout(() => {
                newFlashMessageDiv.style.opacity = '0';
                newFlashMessageDiv.style.maxHeight = '0'; // Collapse
                newFlashMessageDiv.style.overflow = 'hidden';
                newFlashMessageDiv.style.paddingTop = '0';
                newFlashMessageDiv.style.paddingBottom = '0';
                newFlashMessageDiv.style.marginTop = '0';
                newFlashMessageDiv.style.marginBottom = '0';
                setTimeout(() => {
                    newFlashMessageDiv.remove();
                }, 500); // Match CSS transition duration
            }, 5000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Main login form elements
            const loginForm = document.getElementById('loginForm');
            const emailInput = document.getElementById('email');
            const passwordInput = document.getElementById('password');
            const faceLoginSuccessInput = document.getElementById('faceLoginSuccess');
            const recognizedTenantIdInput = document.getElementById('recognizedTenantId');
            const signInBtn = document.getElementById('signInBtn');

            // Modal elements
            const facialRecognitionModal = document.getElementById('facialRecognitionModal');
            const cameraFeedVideo = document.getElementById('cameraFeedVideo');
            const captureCanvas = document.getElementById('captureCanvas');
            const faceBoundingBoxCanvas = document.getElementById('faceBoundingBoxCanvas');
            const faceScanMessage = document.getElementById('faceScanMessage');
            const capturePhotoBtn = document.getElementById('capturePhotoBtn');
            const retakePhotoBtn = document.getElementById('retakePhotoBtn');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const recognitionResultDiv = document.getElementById('recognitionResult');
            const canvasCtx = faceBoundingBoxCanvas.getContext('2d');
            const captureCtx = captureCanvas.getContext('2d');

            let stream = null;
            let faceDetectionInterval = null;
            let labeledFaceDescriptors = []; // To store loaded known tenant face descriptors
            let photoCaptured = false;

            const MODEL_URL = './models'; // Path to face-api.js models
            const KNOWN_FACES_BASE_URL = './models/known_faces'; 
            // Define the specific unit folders to load - MODIFIED TO INCLUDE UNIT_3
            const TARGET_UNIT_FOLDERS = ['Unit_1', 'Unit_2', 'Unit_3']; 
            const DISTANCE_THRESHOLD = 0.6; // Lower is stricter (0.6 is common)
            const IMAGES_PER_UNIT = 3; // Number of reference images expected per unit folder (e.g., 1.jpg, 2.jpg, 3.jpg)

            async function loadModelsAndLabeledFacesFromImages() {
                faceScanMessage.textContent = 'Loading facial recognition models...';
                try {
                    await Promise.all([
                        faceapi.nets.ssdMobilenetv1.loadFromUri(MODEL_URL),
                        faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                        faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
                        faceapi.nets.ageGenderNet.loadFromUri(MODEL_URL)
                    ]);
                    faceScanMessage.textContent = `Models loaded. Loading known faces from ${TARGET_UNIT_FOLDERS.join(', ')}...`; // Updated message

                    // Load descriptors for each specified unit folder
                    labeledFaceDescriptors = await Promise.all(
                        TARGET_UNIT_FOLDERS.map(async unitName => { // Iterate through the defined target folders
                            const descriptions = [];
                            for (let i = 1; i <= IMAGES_PER_UNIT; i++) {
                                try {
                                    // Construct the web-accessible URL for images within each TARGET_UNIT_FOLDER
                                    const imageUrl = `${KNOWN_FACES_BASE_URL}/${unitName}/${i}.jpg`;
                                    console.log(`Attempting to load: ${imageUrl}`);
                                    const img = await faceapi.fetchImage(imageUrl);
                                    if (img && img.complete) {
                                        const detection = await faceapi.detectSingleFace(img).withFaceLandmarks().withFaceDescriptor();
                                        if (detection) {
                                            descriptions.push(detection.descriptor);
                                        } else {
                                            console.warn(`No face detected in ${imageUrl} for unit ${unitName}.`);
                                        }
                                    } else {
                                        console.warn(`Image not loaded or invalid: ${imageUrl} for unit ${unitName}.`);
                                    }
                                } catch (e) {
                                    console.warn(`Could not load or process image for unit ${unitName}, image ${i}:`, e);
                                }
                            }
                            
                            // Only create LabeledFaceDescriptors if at least one descriptor was found for this unit
                            if (descriptions.length > 0) {
                                return new faceapi.LabeledFaceDescriptors(unitName, descriptions);
                            }
                            return null; // Return null if no descriptors were found for this unit
                        })
                    );
                    
                    // Filter out any null entries (units for whom no descriptors could be loaded)
                    labeledFaceDescriptors = labeledFaceDescriptors.filter(ld => ld !== null);

                    if (labeledFaceDescriptors.length > 0) {
                        faceScanMessage.textContent = `Models loaded. Found faces for ${labeledFaceDescriptors.map(ld => ld.label).join(', ')}.`;
                        console.log('Models and known unit faces loaded!', labeledFaceDescriptors);
                        return true;
                    } else {
                        faceScanMessage.textContent = `No facial data could be loaded from ${TARGET_UNIT_FOLDERS.join(', ')}. Face login unavailable.`; // Updated message
                        faceScanMessage.style.color = 'orange';
                        showFlashMessage(`No facial data found in the specified unit folders. Face login will not work.`, 'warning');
                        return false;
                    }
                } catch (error) {
                    console.error('Error loading face-api.js models or known faces from files:', error);
                    faceScanMessage.textContent = 'Error loading facial recognition resources. Face login unavailable.';
                    faceScanMessage.style.color = 'red';
                    capturePhotoBtn.disabled = true;
                    showFlashMessage('Error loading facial recognition resources. Face login may not work.', 'danger');
                    return false;
                }
            }

            async function resetModal() {
                photoCaptured = false;
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    cameraFeedVideo.srcObject = null;
                }
                if (faceDetectionInterval) {
                    clearInterval(faceDetectionInterval);
                    faceDetectionInterval = null;
                }
                cameraFeedVideo.style.display = 'none';
                captureCanvas.style.display = 'none';
                faceBoundingBoxCanvas.style.display = 'block';

                faceScanMessage.textContent = 'Please allow camera access and position your face.';
                faceScanMessage.style.color = '#6C757D';
                capturePhotoBtn.style.display = 'inline-flex';
                capturePhotoBtn.disabled = false;
                retakePhotoBtn.style.display = 'none';
                recognitionResultDiv.textContent = '';
                recognitionResultDiv.className = 'recognition-result';
                canvasCtx.clearRect(0, 0, faceBoundingBoxCanvas.width, faceBoundingBoxCanvas.height);
                captureCtx.clearRect(0, 0, captureCanvas.width, captureCanvas.height);
                recognizedTenantIdInput.value = ''; // Reset hidden input
                faceLoginSuccessInput.value = 'false'; // Reset face login success flag
            }

            async function startVideo() {
                // Ensure models and labeled faces are loaded from images
                if (labeledFaceDescriptors.length === 0 && !(await loadModelsAndLabeledFacesFromImages())) {
                    faceScanMessage.textContent = `Facial login not available. No known faces loaded or models failed.`;
                    showFlashMessage(`Face login not possible. No tenant facial data available or models failed to load.`, 'danger');
                    return;
                }

                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
                    cameraFeedVideo.srcObject = stream;
                    cameraFeedVideo.style.display = 'block';
                    faceBoundingBoxCanvas.style.display = 'block';

                    cameraFeedVideo.onloadedmetadata = () => {
                        cameraFeedVideo.play();
                        faceBoundingBoxCanvas.width = cameraFeedVideo.videoWidth;
                        faceBoundingBoxCanvas.height = cameraFeedVideo.videoHeight;
                        captureCanvas.width = cameraFeedVideo.videoWidth;
                        captureCanvas.height = cameraFeedVideo.videoHeight;
                        faceScanMessage.textContent = 'Camera active. Position your face in the frame.';
                        faceDetectionInterval = setInterval(detectFaces, 100);
                    };

                } catch (err) {
                    console.error('Error accessing camera:', err);
                    let errorMessage = 'Could not access camera.';
                    if (err.name === 'NotAllowedError') {
                        errorMessage += ' Please grant camera permissions.';
                    } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                        errorMessage += ' No camera found. Please ensure one is connected.';
                    } else if (err.name === 'NotReadableError') {
                        errorMessage += ' Camera is in use by another application or not accessible.';
                    }
                    faceScanMessage.textContent = errorMessage;
                    faceScanMessage.style.color = 'red';
                    capturePhotoBtn.disabled = true;
                    showFlashMessage(errorMessage + ' Face login cannot proceed.', 'danger');
                }
            }

            async function detectFaces() {
                if (cameraFeedVideo.paused || cameraFeedVideo.ended || labeledFaceDescriptors.length === 0) {
                    return;
                }

                const displaySize = { width: cameraFeedVideo.videoWidth, height: cameraFeedVideo.videoHeight };
                faceapi.matchDimensions(faceBoundingBoxCanvas, displaySize);

                const detections = await faceapi.detectAllFaces(cameraFeedVideo, new faceapi.SsdMobilenetv1Options())
                    .withFaceLandmarks()
                    .withFaceExpressions()
                    .withAgeAndGender();

                const resizedDetections = faceapi.resizeResults(detections, displaySize);

                canvasCtx.clearRect(0, 0, faceBoundingBoxCanvas.width, faceBoundingBoxCanvas.height);
                faceapi.draw.drawDetections(faceBoundingBoxCanvas, resizedDetections);
                faceapi.draw.drawFaceLandmarks(faceBoundingBoxCanvas, resizedDetections);
                faceapi.draw.drawFaceExpressions(faceBoundingBoxCanvas, resizedDetections);

                if (resizedDetections.length === 0) {
                    faceScanMessage.textContent = 'No face detected. Please center your face.';
                    faceScanMessage.style.color = '#6C757D';
                    capturePhotoBtn.disabled = true;
                    console.log('Face detection: No face detected.'); // Added log
                } else if (resizedDetections.length > 1) {
                    faceScanMessage.textContent = 'Multiple faces detected. Only one person at a time.';
                    faceScanMessage.style.color = 'red';
                    capturePhotoBtn.disabled = true;
                    console.log('Face detection: Multiple faces detected.'); // Added log
                } else {
                    faceScanMessage.textContent = 'Face detected! Ready to capture.';
                    faceScanMessage.style.color = '#28A745';
                    capturePhotoBtn.disabled = false;
                    
                    const detection = resizedDetections[0];
                    const { age, gender, detection: faceDetection } = detection;
                    const box = faceDetection.box;
                    const text = `${faceapi.utils.round(age, 0)} years ${gender}`;
                    new faceapi.draw.DrawTextField([text], box.bottomLeft).draw(faceBoundingBoxCanvas);
                    console.log('Face detection: Face detected. Details:', detection); // Added log
                }
            }

            // --- Event Listeners ---

            // Modified submit listener for the main login form
            loginForm.addEventListener('submit', async function(e) {
                // Client-side validation for email and password
                const email = emailInput.value.trim();
                const password = passwordInput.value.trim();

                if (!email || !password) {
                    e.preventDefault(); // Prevent default form submission if fields are empty
                    showFlashMessage('Please enter both email and password to sign in.', 'danger');
                    return; // Stop here if validation fails
                }
                
                // If email and password are provided, proceed with facial recognition flow
                e.preventDefault(); // Prevent default form submission initially
                facialRecognitionModal.classList.add('show');
                await resetModal();
                await startVideo();
            });


            // Handle Capture Photo button inside the modal
            capturePhotoBtn.addEventListener('click', async function() {
                console.log('Capture Photo button clicked.'); // Added log
                if (!stream || cameraFeedVideo.paused || labeledFaceDescriptors.length === 0) {
                    faceScanMessage.textContent = 'Camera not active or known faces not loaded. Please retry.';
                    recognitionResultDiv.className = 'recognition-result danger';
                    console.error('Cannot capture: stream not active, video paused, or no descriptors loaded.'); // Added log
                    return;
                }
                
                if (faceDetectionInterval) {
                    clearInterval(faceDetectionInterval);
                    faceDetectionInterval = null;
                }

                captureCtx.drawImage(cameraFeedVideo, 0, 0, captureCanvas.width, captureCanvas.height);
                
                recognitionResultDiv.textContent = 'Processing image for recognition...';
                recognitionResultDiv.className = 'recognition-result info';
                console.log('Image captured and processing initiated.'); // Added log

                try {
                    const capturedDetection = await faceapi.detectSingleFace(captureCanvas, new faceapi.SsdMobilenetv1Options())
                        .withFaceLandmarks()
                        .withFaceDescriptor();

                    if (capturedDetection) {
                        console.log('Face detected in captured photo. Descriptor:', capturedDetection.descriptor); // Added log
                        const faceMatcher = new faceapi.FaceMatcher(labeledFaceDescriptors, DISTANCE_THRESHOLD);
                        const bestMatch = faceMatcher.findBestMatch(capturedDetection.descriptor);

                        console.log('Best match found:', bestMatch); // Added log

                        if (bestMatch.distance < DISTANCE_THRESHOLD && TARGET_UNIT_FOLDERS.includes(bestMatch.label)) {
                            const matchedUnitName = bestMatch.label; 
                            const tenantInfo = ALL_TENANT_DATA.find(t => t.unit_name === matchedUnitName);
                            
                            if (tenantInfo) {
                                const matchedTenantId = tenantInfo.tenant_id;
                                const tenantName = tenantInfo.full_name;

                                console.log(`Face recognized as: ${tenantName} (ID: ${matchedTenantId}) from ${matchedUnitName}.`); // Added log

                                // Set hidden fields for server-side verification
                                faceLoginSuccessInput.value = 'true';
                                recognizedTenantIdInput.value = matchedTenantId;
                                
                                // Keep the modal open for a moment to show success, then submit
                                recognitionResultDiv.textContent = `Face recognized as ${tenantName}. Proceeding with login...`;
                                recognitionResultDiv.className = 'recognition-result success';
                                setTimeout(() => {
                                    facialRecognitionModal.classList.remove('show');
                                    loginForm.submit(); // This submits the form!
                                }, 1500); // Give user time to see success message

                            } else {
                                console.warn(`Match found for unit ${matchedUnitName}, but no corresponding tenant found in ALL_TENANT_DATA.`); // Added log
                                recognitionResultDiv.textContent = `Matched ${matchedUnitName}, but no corresponding tenant found in the database for login. Please try again or use email/password.`;
                                recognitionResultDiv.className = 'recognition-result danger';
                                faceLoginSuccessInput.value = 'false';
                                recognizedTenantIdInput.value = '';
                            }

                        } else {
                            // Updated message for units not recognized (now includes Unit 3)
                            console.log('No confident face match found or not in target units. Login prevented.'); // Added log
                            recognitionResultDiv.textContent = `Face not recognized as a registered tenant for ${TARGET_UNIT_FOLDERS.map(u => u.replace('_', ' ')).join(', ')}. Please ensure your face is registered or try again.`;
                            recognitionResultDiv.className = 'recognition-result danger';
                            faceLoginSuccessInput.value = 'false';
                            recognizedTenantIdInput.value = '';
                        }
                    } else {
                        console.log('No face detected in the captured photo for recognition.'); // Added log
                        recognitionResultDiv.textContent = 'No face detected in the captured photo. Please ensure your face is fully visible and try again.';
                        recognitionResultDiv.className = 'recognition-result danger';
                        faceLoginSuccessInput.value = 'false';
                        recognizedTenantIdInput.value = '';
                    }
                } catch (error) {
                    console.error('Error during client-side recognition:', error); // Added log
                    recognitionResultDiv.textContent = 'An unexpected error occurred during face recognition. Please try again.';
                    recognitionResultDiv.className = 'recognition-result danger';
                    faceLoginSuccessInput.value = 'false';
                    recognizedTenantIdInput.value = '';
                }

                photoCaptured = true;
                cameraFeedVideo.pause();
                cameraFeedVideo.style.display = 'none';
                captureCanvas.style.display = 'block';
                faceBoundingBoxCanvas.style.display = 'none';
                canvasCtx.clearRect(0, 0, faceBoundingBoxCanvas.width, faceBoundingBoxCanvas.height);

                // faceScanMessage.textContent = 'Photo captured! Review the result.'; // This will be overwritten by recognitionResultDiv
                capturePhotoBtn.style.display = 'none';
                retakePhotoBtn.style.display = 'inline-flex';
            });

            retakePhotoBtn.addEventListener('click', function() {
                console.log('Retake Photo button clicked.'); // Added log
                resetModal();
                startVideo();
            });

            closeModalBtn.addEventListener('click', function() {
                console.log('Close Modal button clicked.'); // Added log
                facialRecognitionModal.classList.remove('show');
                resetModal();
            });

            // Initial load of models and tenant info (not descriptors) when the page loads
            loadModelsAndLabeledFacesFromImages();
        });
    </script>
</body>
</html>