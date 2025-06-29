<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Include your database connection file. This file MUST create a $conn variable
// that is a valid MySQLi database connection object.
require_once 'db_connect.php';

// Check for database connection at the very beginning
if (!isset($conn) || !$conn instanceof mysqli || $conn->connect_error) {
    error_log("CRITICAL ERROR: Database connection failed in visitorform.php: " . ($conn->connect_error ?? 'Unknown error'));
    // Redirect or show a more user-friendly error page if connection fails
    $_SESSION['flash_message'] = "Critical: Database connection failed. Please contact support.";
    $_SESSION['flash_message_type'] = "danger";
    header("Location: login.php"); // Or an error.php page
    exit();
}

// Function to safely output HTML to prevent Cross-Site Scripting (XSS) vulnerabilities.
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Initialize variables for flash messages and dynamic dropdowns
$flash_message = '';
$flash_message_type = '';
$unit_options_html = '<option value="" disabled selected>Select Unit</option>'; // For Unit Number dropdown
$host_options_data = []; // To store data for JavaScript to populate hostName dropdown dynamically

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve and sanitize form inputs
    $fullName = trim($_POST['name'] ?? '');
    $unitId = trim($_POST['unit'] ?? ''); // This will be the unit_id from the dropdown
    $phoneNumber = trim($_POST['phone'] ?? '');
    $purposeOfVisit = trim($_POST['purpose'] ?? '');

    // IMPORTANT: hostName now contains "type_id" (e.g., "tenant_1" or "member_5")
    $hostCombinedId = trim($_POST['hostName'] ?? '');
    $hostType = ''; // Will be extracted from $hostCombinedId (e.g., 'tenant' or 'member')
    $hostId = '';   // Will be extracted from $hostCombinedId (the actual tenant_id or added_member_id)

    // Parse hostCombinedId to get hostType and actual hostId
    if (!empty($hostCombinedId)) {
        $parts = explode('_', $hostCombinedId);
        if (count($parts) === 2) {
            $hostType = $parts[0]; // 'tenant' or 'member'
            $hostId = $parts[1];   // The actual ID (tenant_id or added_member_id)
        }
    }

    $relationshipToTenant = trim($_POST['relationship'] ?? ''); // No 'Other' handling here

    // New hidden field from JavaScript for facial recognition status (set by JS, then confirmed by server-side recognition)
    // This value is primarily for indicating if a photo was PROVIDED by the client,
    // not necessarily if a match was found by Luxand. That's determined server-side.
    $facialRecognitionAttempted = filter_var(($_POST['facial_recognition_attempted'] ?? 'false'), FILTER_VALIDATE_BOOLEAN);


    $errors = [];

    // Basic Validation
    if (empty($fullName)) { $errors[] = "Full Name is required."; }
    if (empty($unitId)) { $errors[] = "Unit Number is required."; }
    if (empty($phoneNumber)) { $errors[] = "Phone Number is required."; }
    if (!preg_match("/^\d{11}$/", $phoneNumber)) { $errors[] = "Phone number must be exactly 11 digits."; }
    if (empty($purposeOfVisit)) { $errors[] = "Purpose of Visit is required."; }
    if (empty($hostId) || empty($hostType)) { $errors[] = "Person to Visit is required."; } // Validate hostId and hostType
    if (empty($relationshipToTenant)) { $errors[] = "Relationship to the Tenant is required."; }

    // Make facialImage required
    if (!isset($_FILES['facialImage']) || $_FILES['facialImage']['error'] == UPLOAD_ERR_NO_FILE || !empty($_FILES['facialImage']['tmp_name']) === false) {
        $errors[] = "Face Scan is required. Please capture a photo.";
    }

    $profilePicPath = null;
    if (empty($errors)) { // Only proceed with file upload if no prior errors (e.g., file missing)
        if (isset($_FILES['facialImage']) && $_FILES['facialImage']['error'] == UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['facialImage']['tmp_name'];
            $fileName = $_FILES['facialImage']['name'];
            $fileSize = $_FILES['facialImage']['size'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = './uploads/visitor_faces/'; // Adjust path as needed
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true); // Create directory if it doesn't exist
                }
                $newFileName = uniqid('visitor_') . '.' . $fileExtension;
                $destPath = $uploadDir . $newFileName;

                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $profilePicPath = $destPath;
                } else {
                    $errors[] = "Error uploading face image.";
                    error_log("Failed to move uploaded file: " . $_FILES['facialImage']['error']);
                }
            } else {
                $errors[] = "Invalid face image format. Only JPG, JPEG, PNG, GIF allowed.";
            }
        }
    }


    if (empty($errors)) {
        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = null;
            try {
                // Determine the host_tenant_id based on hostType
                $finalHostTenantId = null; // This will be the ID of the main tenant for the unit
                $hostFullNameForEmail = ''; // To store the full name of the specific host (tenant or added member) for email notification

                // Variables to store the specific host selected
                $specificHostId = null;
                $specificHostType = '';

                if ($hostType === 'tenant') {
                    $finalHostTenantId = $hostId; // If host is the main tenant, their ID is the tenant_id
                    $specificHostId = $hostId;
                    $specificHostType = 'tenant';
                    // Fetch tenant's name for the email
                    $stmt_host_name = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS full_name FROM tenants WHERE tenant_id = ?");
                    $stmt_host_name->bind_param("i", $hostId);
                    $stmt_host_name->execute();
                    $result_host_name = $stmt_host_name->get_result();
                    if ($result_host_name->num_rows > 0) {
                        $hostFullNameForEmail = $result_host_name->fetch_assoc()['full_name'];
                    }
                    $stmt_host_name->close();
                } elseif ($hostType === 'member') {
                    // If host is an added member, we need their tenant_id (the main unit tenant)
                    $stmt_member_tenant = $conn->prepare("SELECT tenant_id, full_name FROM addedmembers WHERE added_member_id = ?");
                    $stmt_member_tenant->bind_param("i", $hostId);
                    $stmt_member_tenant->execute();
                    $result_member_tenant = $stmt_member_tenant->get_result();
                    if ($result_member_tenant->num_rows > 0) {
                        $member_info = $result_member_tenant->fetch_assoc();
                        $finalHostTenantId = $member_info['tenant_id']; // This is the tenant_id linked to the added member
                        $hostFullNameForEmail = $member_info['full_name']; // The name of the added member
                        $specificHostId = $hostId;
                        $specificHostType = 'member';
                    }
                    $stmt_member_tenant->close();
                }

                if (empty($finalHostTenantId) || empty($specificHostId) || empty($specificHostType)) {
                     $_SESSION['flash_message'] = "Error: Host not found or invalid host type. Please select a valid person to visit.";
                     $_SESSION['flash_message_type'] = "danger";
                     header("Location: visitorform.php");
                     exit();
                }

                // SQL to insert new visitor - NOW INCLUDES specific_host_id AND specific_host_type
                $sql = "INSERT INTO visitors (full_name, unit_id, phone_number, purpose_of_visit, host_tenant_id, specific_host_id, specific_host_type, relationship_to_tenant, profile_pic_path, status, action_token, token_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $status = 'pending'; // New visitors are always pending initially
                $actionToken = bin2hex(random_bytes(32)); // Generate a random 64-character hex token
                $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token valid for 1 hour from registration

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    // 'sisssissss' -> string, integer, string, string, integer, string, string, string, string, string
                    // Note: Use $finalHostTenantId here for the host_tenant_id
                    // NEW: 'sisssissssss' -> (s)fullName, (i)unitId, (s)phoneNumber, (s)purposeOfVisit, (i)finalHostTenantId, (i)specificHostId, (s)specificHostType, (s)relationshipToTenant, (s)profilePicPath, (s)status, (s)actionToken, (s)tokenExpiresAt
                    $stmt->bind_param("sisssissssss", $fullName, $unitId, $phoneNumber, $purposeOfVisit, $finalHostTenantId, $specificHostId, $specificHostType, $relationshipToTenant, $profilePicPath, $status, $actionToken, $tokenExpiresAt);

                    if ($stmt->execute()) {
                        $_SESSION['flash_message'] = "Visitor '{$fullName}' registered successfully! They will be verified shortly.";
                        $_SESSION['flash_message_type'] = "success";

                        // Facial recognition is now a client-side *attempt* indication, not server-side check.
                        // Since Luxand API is removed, this will always be false for the email notification
                        // unless you integrate another server-side recognition system.
                        $facialRecognitionMatchFound = false;
                        
                        // --- Email tenant notification ---
                        $tenantEmail = '';
                        $mainTenantName = ''; // This will be the actual tenant's name (the one who receives the email)
                        $unitNumber = '';

                        // Fetch the *actual unit tenant's* email address and unit number
                        $sql_get_main_tenant_info = "SELECT email, CONCAT(first_name, ' ', last_name) AS full_name, u.unit_number
                                                     FROM tenants t
                                                     JOIN units u ON t.unit_id = u.unit_id
                                                     WHERE t.tenant_id = ?";
                        $stmt_main_tenant = $conn->prepare($sql_get_main_tenant_info);
                        if ($stmt_main_tenant) {
                            // Use $finalHostTenantId to get the main tenant's info
                            $stmt_main_tenant->bind_param("i", $finalHostTenantId);
                            $stmt_main_tenant->execute();
                            $result_main_tenant = $stmt_main_tenant->get_result();
                            if ($result_main_tenant->num_rows > 0) {
                                $main_tenant_info = $result_main_tenant->fetch_assoc();
                                $tenantEmail = $main_tenant_info['email'];
                                $mainTenantName = $main_tenant_info['full_name'];
                                $unitNumber = $main_tenant_info['unit_number'];
                            }
                            $stmt_main_tenant->close();
                        } else {
                            error_log("Failed to prepare statement for fetching main tenant info: " . $conn->error);
                        }

                        if (!empty($tenantEmail)) {
                            $currentHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                            $acceptUrl = $currentHost . "/handle_visitor_action.php?action=accept&token=" . urlencode($actionToken);
                            $rejectUrl = $currentHost . "/handle_visitor_action.php?action=reject&token=" . urlencode($actionToken);

                            $subject = "Visitor Request for Unit {$unitNumber}: {$fullName}";
                            $message = "Dear {$mainTenantName},\n\n"; // Use the actual tenant's name here
                            $message .= "A new visitor has registered for your unit:\n\n";
                            $message .= "Visitor Name: {$fullName}\n";
                            $message .= "Unit Number: {$unitNumber}\n";
                            $message .= "Phone Number: {$phoneNumber}\n";
                            $message .= "Purpose of Visit: {$purposeOfVisit}\n";
                            $message .= "Person to Visit: {$hostFullNameForEmail}\n"; // Display the name of the specific host (tenant or member)
                            $message .= "Relationship to Host: {$relationshipToTenant}\n\n";

                            // Message regarding facial capture, now that Luxand is removed
                            $message .= "This visitor's photo has been captured for security purposes.\n\n";

                            $message .= "To ACCEPT this visitor: {$acceptUrl}\n";
                            $message .= "To REJECT this visitor: {$rejectUrl}\n\n";
                            $message .= "These links are valid for 1 hour. If no action is taken, the visitor will remain pending.\n\n";
                            $message .= "For more details or to manage this request, please log into the Verifaced system.\n\n";
                            $message .= "Thank you,\nVerifaced Security Team";

                            $headers = "From: no-reply@yourapartmentcomplex.com\r\n";
                            $headers .= "Reply-To: no-reply@yourapartmentcomplex.com\r\n";
                            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

                            if (mail($tenantEmail, $subject, $message, $headers)) {
                                error_log("Visitor notification email sent to {$tenantEmail} for {$fullName}.");
                            } else {
                                error_log("Failed to send visitor notification email to {$tenantEmail} for {$fullName}. PHP mail() error.");
                            }
                        } else {
                            error_log("Tenant email not found or empty for tenant_id: {$finalHostTenantId}. Could not send visitor notification email.");
                        }
                        // --- End Email logic ---

                        // Redirect to self to clear POST data and show message
                        header("Location: visitorform.php");
                        exit();
                    } else {
                        $_SESSION['flash_message'] = "Error registering visitor: " . $stmt->error;
                        $_SESSION['flash_message_type'] = "danger";
                        error_log("Error inserting new visitor: " . $stmt->error);
                    }
                } else {
                    $_SESSION['flash_message'] = "Database error: Could not prepare visitor registration statement.";
                    $_SESSION['flash_message_type'] = "danger";
                    error_log("Failed to prepare statement for visitor registration: " . $conn->error);
                }
            } catch (Throwable $e) {
                error_log("Visitor registration error: " . $e->getMessage());
                $_SESSION['flash_message'] = "An unexpected error occurred during visitor registration.";
                $_SESSION['flash_message_type'] = "danger";
            } finally {
                if ($stmt) {
                    $stmt->close();
                }
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Unable to register visitor.";
            $_SESSION['flash_message_type'] = "danger";
            error_log("Database connection variable \$conn is not set or not a mysqli object in visitorform.php (POST).");
        }
    } else {
        // If there are validation errors, store them in a flash message
        $_SESSION['flash_message'] = implode("<br>", $errors);
        $_SESSION['flash_message_type'] = "danger";
    }
    // Redirect to self to display flash messages (Post/Redirect/Get pattern)
    header("Location: visitorform.php");
    exit();
}

// --- Dynamic Dropdown Population (runs on GET request or after POST redirect) ---
if (isset($conn) && $conn instanceof mysqli) {
    // Populate Unit Number dropdown
    $sql_units = "SELECT unit_id, unit_number FROM units ORDER BY CAST(SUBSTRING_INDEX(unit_number, ' ', -1) AS UNSIGNED) ASC";
    $result_units = $conn->query($sql_units);

    if ($result_units) {
        if ($result_units->num_rows > 0) {
            while ($row_unit = $result_units->fetch_assoc()) {
                $unit_options_html .= "<option value='" . e($row_unit['unit_id']) . "'>" . e($row_unit['unit_number']) . "</option>";
            }
            $result_units->free();
        } else {
            $unit_options_html = '<option value="" disabled>No units found</option>';
        }
    } else {
        $unit_options_html = '<option value="" disabled>Error loading units</option>';
        error_log("Error fetching units for dropdown: " . $conn->error);
    }

    // Prepare data for "Person to Visit" dropdown (Tenants and Registered Members)
    // This data will be passed to JavaScript to dynamically populate the second dropdown
    try {
        $sql_hosts = "
            SELECT
                t.unit_id,
                t.tenant_id AS id,
                CONCAT(t.first_name, ' ', t.last_name) AS full_name,
                'tenant' AS type
            FROM tenants t

            UNION ALL

            SELECT
                am.unit_id, -- Now that addedmembers table has unit_id, we can select it directly
                am.added_member_id AS id,
                am.full_name,
                'member' AS type
            FROM addedmembers am
            ORDER BY full_name ASC;
        ";
        $result_hosts = $conn->query($sql_hosts);

        if ($result_hosts) {
            while ($row_host = $result_hosts->fetch_assoc()) {
                $host_options_data[] = [
                    'unit_id' => $row_host['unit_id'],
                    'id' => $row_host['id'],
                    'full_name' => e($row_host['full_name']),
                    'type' => $row_host['type']
                ];
            }
            $result_hosts->free();
        } else {
            error_log("Error fetching hosts for dropdown: " . $conn->error);
        }
    } catch (Throwable $e) {
        error_log("Error preparing host data for dropdown: " . $e->getMessage());
    }

} else {
    // Database connection error for dropdowns
    $unit_options_html = '<option value="" disabled>Database connection error</option>';
    error_log("Database connection not available for dropdowns in visitorform.php (GET).");
}

// After PHP processing, retrieve flash messages for display
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced - Visitor Registration</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Professional Khaki Theme CSS */
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

            /* Shadows */
            --shadow-sm: 0 2px 4px rgba(139, 134, 78, 0.1);
            --shadow-md: 0 4px 12px rgba(139, 134, 78, 0.15);
            --shadow-lg: 0 8px 24px rgba(139, 134, 78, 0.2);
            --shadow-xl: 0 12px 32px rgba(139, 134, 78, 0.25);

            /* Border Radius */
            --radius-sm: 6px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;

            /* Transitions */
            --transition-fast: 0.2s ease;
            --transition-normal: 0.3s ease;
            --transition-slow: 0.5s ease;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--khaki-light) 0%, var(--khaki-muted) 50%, var(--khaki-accent) 100%);
            min-height: 100vh;
            color: var(--gray-800);
            line-height: 1.6;
            font-size: 16px;
            display: flex;
        }

        /* SIDEBAR STYLES */
        .sidebar {
            width: 300px;
            background: linear-gradient(180deg, var(--white) 0%, var(--off-white) 100%);
            backdrop-filter: blur(20px);
            padding: 32px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: var(--shadow-lg);
            position: relative;
            border-right: 1px solid var(--gray-200);
            /* Prevent horizontal scroll from sidebar itself */
            overflow-x: hidden;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        }

        .logo-circle {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-weight: 700;
            font-size: 24px;
            margin-bottom: 16px;
            box-shadow: var(--shadow-md);
            border: 3px solid var(--white);
            transition: var(--transition-normal);
        }

        .logo-circle:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .logo-circle::before {
            content: "V";
        }

        .sidebar h2 {
            color: var(--gray-800);
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 48px;
            text-align: center;
            letter-spacing: -0.5px;
        }

        .sidebar nav ul {
            list-style: none;
            width: 100%;
            padding: 0 24px;
            display: flex;
            flex-direction: column;
            gap: 8px;
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
            gap: 16px;
            width: 100%;
            padding: 16px 20px;
            font-weight: 500;
            font-size: 15px;
            transition: var(--transition-normal);
            border-radius: var(--radius-md);
        }

        .sidebar nav li:hover {
            transform: translateX(4px);
        }

        .sidebar nav li:hover a {
            color: var(--khaki-dark);
            background: linear-gradient(135deg, var(--khaki-light) 0%, var(--khaki-muted) 100%);
        }

        .sidebar nav li.active a {
            background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
            color: var(--white);
            font-weight: 600;
            box-shadow: var(--shadow-md);
        }

        .sidebar nav li.active {
            transform: translateX(4px);
        }

        .badge {
            background: var(--danger);
            color: var(--white);
            border-radius: 12px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            margin-left: auto;
            min-width: 20px;
            text-align: center;
            box-shadow: var(--shadow-sm);
        }

        .sidebar .back-to-login {
            position: absolute;
            bottom: 32px;
            left: 24px;
            right: 24px;
        }

        .sidebar .back-to-login a {
            padding: 16px;
            background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
            color: var(--white);
            text-decoration: none;
            border-radius: var(--radius-md);
            text-align: center;
            font-weight: 600;
            font-size: 15px;
            transition: var(--transition-normal);
            border: 1px solid transparent;
            box-shadow: var(--shadow-md);
            display: block;
        }

        .sidebar .back-to-login a:hover {
            background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        /* MAIN CONTENT STYLES */
        .main-content {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: linear-gradient(135deg,
                rgba(245, 245, 220, 0.3) 0%,
                rgba(230, 226, 195, 0.3) 50%,
                rgba(221, 211, 160, 0.3) 100%);
            min-height: 100vh;
            /* Prevent horizontal scroll */
            overflow-x: hidden;
        }

        .content-header {
            margin-bottom: 20px;
            text-align: center;
        }

        .content-header h2 {
            color: var(--gray-800);
            font-size: 28px;
            font-weight: 800;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
            text-shadow: 0 2px 4px rgba(139, 134, 78, 0.1);
        }

        .content-header p {
            color: var(--gray-600);
            font-size: 14px;
            font-weight: 400;
        }

        .form-container {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 24px;
            max-width: 900px;
            width: 100%;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        .form-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 11px;
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            background: var(--white);
            color: var(--gray-800);
            transition: var(--transition-normal);
        }

        .form-group select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url('data:image/svg+xml;utf8,<svg fill="%236C757D" height="24" viewBox="0 0 24 24" width="24" xmlns="http://www.w3.org/2000/svg"><path d="M7 10l5 5 5-5z"/><path d="M0 0h24v24H0z" fill="none"/></svg>');
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 20px;
            cursor: pointer;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--khaki-primary);
            background: var(--off-white);
            box-shadow: 0 0 0 4px rgba(189, 183, 107, 0.1);
            transform: translateY(-1px);
        }

        .form-group input[type="file"] {
            padding: 12px 20px;
            border: 2px dashed var(--gray-300);
            background: var(--gray-100);
            cursor: pointer;
            text-overflow: ellipsis; /* For long file names */
            white-space: nowrap;
            overflow: hidden;
        }

        .form-group input[type="file"]:hover {
            border-color: var(--khaki-primary);
            background: var(--khaki-light);
        }

        .form-group input[type="file"]:focus {
            border-color: var(--khaki-primary);
            background: var(--khaki-light);
        }

        .buttons {
            display: flex;
            gap: 20px;
            justify-content: flex-start;
            margin-top: 8px;
        }

        .btn {
            padding: 16px 32px;
            border: none;
            border-radius: var(--radius-md);
            font-size: 16px;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            transition: var(--transition-normal);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-width: 160px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
            color: var(--white);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .btn-secondary {
            background: transparent;
            color: var(--khaki-dark);
            border: 2px solid var(--khaki-primary);
        }

        .btn-secondary:hover {
            background: var(--khaki-primary);
            color: var(--white);
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* MESSAGES */
        .message {
            padding: 16px 24px;
            border-radius: var(--radius-md);
            margin-bottom: 24px;
            font-weight: 600;
            display: none;
            transition: var(--transition-normal);
            text-align: center;
            border: 1px solid transparent;
        }

        .message.success {
            background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.05) 100%);
            color: var(--success);
            border-color: rgba(40, 167, 69, 0.2);
        }

        .message.error,
        .message.danger {
            background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
            color: var(--danger);
            border-color: rgba(220, 53, 69, 0.2);
        }

        .message.info {
            background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
            color: var(--info);
            border-color: rgba(23, 162, 184, 0.2);
        }

        .message.show {
            display: block;
            animation: slideInDown 0.3s ease-out;
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* MODAL STYLES */
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
            background: var(--white);
            padding: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            width: 90%;
            max-width: 600px;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
            position: relative;
            text-align: center;
            border: 1px solid var(--gray-200);
        }

        .modal.show .modal-content {
            transform: translateY(0);
        }

        .modal-header {
            margin-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: 15px;
        }

        .modal-header h3 {
            font-size: 24px;
            color: var(--gray-800);
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
        .camera-feed-container img { /* Add video to the selector */
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover; /* Ensures the image/video covers the container */
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

        #faceScanMessage {
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

        .recognition-result {
            margin-top: 15px;
            padding: 10px 15px;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 15px;
            text-align: center;
            min-height: 20px;
        }

        .recognition-result.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success);
        }

        .recognition-result.danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger);
        }

        /* Responsive adjustments for modal */
        @media (max-width: 768px) {
            .modal-content {
                padding: 20px;
            }
            .modal-header h3 {
                font-size: 20px;
            }
            .modal-actions {
                flex-direction: column;
                gap: 10px;
            }
            .modal-actions .btn {
                width: 100%;
            }
        }


        /* RESPONSIVE DESIGN */
        @media (max-width: 1024px) {
            .sidebar {
                width: 260px;
            }

            .main-content {
                padding: 20px;
            }

            .form-container {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr 1fr;
                gap: 12px 16px;
            }
        }

        @media (max-width: 768px) {
            body {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                height: auto;
                padding: 16px 0;
                box-shadow: var(--shadow-md);
            }

            .sidebar nav ul {
                display: flex;
                gap: 8px;
                overflow-x: auto;
                padding: 0 16px;
                justify-content: center;
            }

            .sidebar nav li {
                white-space: nowrap;
                min-width: auto;
            }

            .sidebar nav li a {
                padding: 10px 14px;
                font-size: 13px;
            }

            .sidebar .back-to-login {
                position: static;
                margin-top: 16px;
                padding: 0 16px;
            }

            .main-content {
                padding: 16px;
                justify-content: flex-start;
                min-height: auto;
            }

            .content-header {
                margin-bottom: 16px;
            }

            .content-header h2 {
                font-size: 24px;
            }

            .form-container {
                padding: 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .buttons {
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
            }

            .btn {
                width: 100%;
                min-width: unset;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 12px;
            }

            .form-container {
                padding: 16px;
            }

            .content-header h2 {
                font-size: 22px;
            }

            .form-grid {
                gap: 10px;
            }

            .form-group label {
                font-size: 10px;
            }

            .form-group input,
            .form-group select {
                padding: 8px 10px;
                font-size: 13px;
            }
        }

        /* ANIMATIONS */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-container {
            animation: fadeInUp 0.6s ease-out;
        }

        /* FOCUS STYLES FOR ACCESSIBILITY */
        .btn:focus,
        .form-group input:focus,
        .form-group select:focus,
        .sidebar nav li a:focus {
            outline: 2px solid var(--khaki-primary);
            outline-offset: 2px;
        }

        /* PRINT STYLES */
        @media print {
            .sidebar,
            .buttons,
            .modal { /* Hide modal during print */
                display: none;
            }

            .main-content {
                padding: 0;
            }

            .form-container {
                box-shadow: none;
                border: 1px solid var(--gray-300);
            }
        }
    </style>
</head>

<body>
    <div class="sidebar">
        <div class="logo-circle"></div>
        <h2>Verifaced</h2>
        <nav>
            <ul>
                <li class="active"><a><span></span> Visitor Registration</a></li>
            </ul>
        </nav>
        <div class="back-to-login">
            <a href="login.php" id="backToLogin">Back to Login</a>
        </div>
    </div>

    <div class="main-content">
        <div class="content-header">
            <h2>Visitor Registration</h2>
        </div>

        <div class="form-container">
            <div id="feedbackMessage" class="message <?php echo $flash_message_type; ?> <?php echo ($flash_message ? 'show' : ''); ?>">
                <?php echo $flash_message; ?>
            </div>

            <form id="registrationForm" method="POST" action="visitorform.php" enctype="multipart/form-data">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="name">Full Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="unit">Unit Number *</label>
                        <select id="unit" name="unit" required>
                            <?php echo $unit_options_html; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number *</label>
                        <input type="tel" id="phone" name="phone" pattern="[0-9]{11}" maxlength="11" required>
                    </div>
                    <div class="form-group">
                        <label for="purpose">Purpose of Visit *</label>
                        <input type="text" id="purpose" name="purpose" required>
                    </div>
                    <div class="form-group">
                        <label for="hostName">Person to Visit *</label>
                        <select id="hostName" name="hostName" required>
                            <option value="" disabled selected>Select Unit First</option>
                        </select>
                        </div>
                    <div class="form-group">
                        <label for="relationship">Relationship to the Tenant *</label>
                        <select id="relationship" name="relationship" required>
                            <option value="" disabled selected>Select Relationship</option>
                            <option value="Parent">Parent</option>
                            <option value="Sibling">Sibling</option>
                            <option value="Child">Child</option>
                            <option value="Spouse">Spouse</option>
                            <option value="Cousin">Cousin</option>
                            <option value="Friend">Friend</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="facialImage">Face Scan *</label> <input type="file" id="facialImage" name="facialImage" accept="image/*" style="display:none;">
                        <button type="button" class="btn btn-secondary" id="openFaceScanModalBtn">Start Face Scan</button>
                        <small id="facialImageStatus" style="font-size:12px; color:var(--gray-500); margin-top:4px; text-align: right;"></small>
                    </div>
                    <input type="hidden" id="facialRecognitionAttempted" name="facial_recognition_attempted" value="false">
                </div>

                <div class="buttons">
                    <button type="submit" class="btn btn-primary">✓ Submit Registration</button>
                </div>
            </form>
        </div>
    </div>

    <div id="facialRecognitionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Face Scan</h3>
            </div>
            <div class="modal-body">
                <div class="camera-feed-container">
                    <video id="cameraFeedVideo" autoplay playsinline style="display:none;"></video>
                    <canvas id="captureCanvas" style="display:none;"></canvas>
                    </div>
                <p id="faceScanMessage">Please allow camera access and position your face.</p>
                <div class="modal-actions">
                    <button type="button" class="btn btn-primary" id="capturePhotoBtn">Capture Photo</button>
                    <button type="button" class="btn btn-secondary" id="retakePhotoBtn" style="display:none;">Retake Photo</button>
                    <button type="button" class="btn btn-secondary" id="closeModalBtn">✕ Done/Close</button>
                </div>
                <div id="recognitionResult" class="recognition-result"></div>
            </div>
        </div>
    </div>

    <script>
        // Data passed from PHP for host dropdown
        const allHostOptions = <?php echo json_encode($host_options_data); ?>;

        function showAppMessage(messageText, type) {
            const messageElement = document.getElementById('feedbackMessage');
            messageElement.innerHTML = messageText; // Use innerHTML to allow for <br> tags
            messageElement.className = `message ${type} show`;

            setTimeout(() => {
                messageElement.classList.remove('show');
            }, 5000);
        }

        document.addEventListener('DOMContentLoaded', function() {
            const unitSelect = document.getElementById('unit');
            const hostNameSelect = document.getElementById('hostName');
            const registrationForm = document.getElementById('registrationForm');
            const openFaceScanModalBtn = document.getElementById('openFaceScanModalBtn'); // New button to open modal
            const facialImageInput = document.getElementById('facialImage');
            const facialImageStatus = document.getElementById('facialImageStatus');
            const facialRecognitionAttemptedInput = document.getElementById('facialRecognitionAttempted');

            // Modal elements
            const facialRecognitionModal = document.getElementById('facialRecognitionModal');
            const cameraFeedVideo = document.getElementById('cameraFeedVideo');
            const captureCanvas = document.getElementById('captureCanvas');
            // const faceBoundingBoxCanvas = document.getElementById('faceBoundingBoxCanvas'); // Not needed without detection
            const faceScanMessage = document.getElementById('faceScanMessage');
            const capturePhotoBtn = document.getElementById('capturePhotoBtn');
            const retakePhotoBtn = document.getElementById('retakePhotoBtn');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const recognitionResultDiv = document.getElementById('recognitionResult');
            // const canvasCtx = faceBoundingBoxCanvas.getContext('2d'); // Not needed without detection
            const captureCtx = captureCanvas.getContext('2d');

            let photoCaptured = false;
            let stream = null;
            let capturedImageDataUrl = null;

            async function resetModal() {
                photoCaptured = false;
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                    cameraFeedVideo.srcObject = null;
                }
                cameraFeedVideo.style.display = 'none';
                captureCanvas.style.display = 'none';
                // faceBoundingBoxCanvas.style.display = 'block'; // Not needed
                faceScanMessage.textContent = 'Please allow camera access and position your face.';
                capturePhotoBtn.style.display = 'inline-flex';
                capturePhotoBtn.disabled = false;
                retakePhotoBtn.style.display = 'none';
                recognitionResultDiv.textContent = '';
                recognitionResultDiv.className = 'recognition-result';
                // canvasCtx.clearRect(0, 0, faceBoundingBoxCanvas.width, faceBoundingBoxCanvas.height); // Not needed
                captureCtx.clearRect(0, 0, captureCanvas.width, captureCanvas.height);
                capturedImageDataUrl = null;
                facialRecognitionAttemptedInput.value = 'false'; // Reset this when retaking
            }

            // --- Event Listeners ---

            unitSelect.addEventListener('change', function() {
                const selectedUnitId = this.value;
                hostNameSelect.innerHTML = '<option value="" disabled selected>Select Person to Visit</option>';

                if (selectedUnitId) {
                    // Filter hosts directly from the allHostOptions array
                    const filteredHosts = allHostOptions.filter(host => parseInt(host.unit_id) === parseInt(selectedUnitId));

                    if (filteredHosts.length > 0) {
                        filteredHosts.forEach(host => {
                            const option = document.createElement('option');
                            // Combine ID and type for the value to easily parse on server-side
                            option.value = `${host.type}_${host.id}`; // e.g., "tenant_1" or "member_5"
                            let label = host.full_name;
                            if (host.type === 'tenant') {
                                label += ' (Tenant)';
                            } else if (host.type === 'member') {
                                label += ' (Registered Member)';
                            }
                            option.textContent = label;
                            hostNameSelect.appendChild(option);
                        });
                    } else {
                        hostNameSelect.innerHTML = '<option value="" disabled selected>No persons found for this unit</option>';
                    }
                } else {
                    hostNameSelect.innerHTML = '<option value="" disabled selected>Select Unit First</option>';
                }
            });

            // IMPORTANT: Add this to ensure the dropdown populates on page load if a unit is already selected (e.g., after a form submission redirect)
            // This will trigger the 'change' event manually.
            if (unitSelect.value) {
                unitSelect.dispatchEvent(new Event('change'));
            }

            registrationForm.addEventListener('submit', function(e) {
                const nameInput = document.getElementById('name');
                const unitInput = document.getElementById('unit');
                const phoneInput = document.getElementById('phone');
                const purposeInput = document.getElementById('purpose');
                const hostNameInput = document.getElementById('hostName');
                const relationshipInput = document.getElementById('relationship');

                let isValid = true;
                let errors = [];

                // Reset borders
                [nameInput, unitInput, phoneInput, purposeInput, hostNameInput, relationshipInput].forEach(input => {
                    input.style.borderColor = 'var(--gray-200)';
                });
                facialImageStatus.style.color = 'var(--gray-500)';

                if (!nameInput.value.trim()) {
                    isValid = false;
                    nameInput.style.borderColor = 'var(--danger)';
                    errors.push("Full Name is required.");
                }
                if (!unitInput.value) {
                    isValid = false;
                    unitInput.style.borderColor = 'var(--danger)';
                    errors.push("Unit Number is required.");
                }
                if (phoneInput.value.trim() === '') {
                    isValid = false;
                    phoneInput.style.borderColor = 'var(--danger)';
                    errors.push("Phone Number is required.");
                } else if (!/^\d{11}$/.test(phoneInput.value.trim())) {
                    isValid = false;
                    phoneInput.style.borderColor = 'var(--danger)';
                    errors.push("Phone number must be exactly 11 digits.");
                }
                if (!purposeInput.value.trim()) {
                    isValid = false;
                    purposeInput.style.borderColor = 'var(--danger)';
                    errors.push("Purpose of Visit is required.");
                }
                if (!hostNameInput.value) {
                    isValid = false;
                    hostNameInput.style.borderColor = 'var(--danger)';
                    errors.push("Person to Visit is required.");
                }
                if (!relationshipInput.value) {
                    isValid = false;
                    relationshipInput.style.borderColor = 'var(--danger)';
                    errors.push("Relationship to the Tenant is required.");
                }

                // Face Scan Validation
                if (facialImageInput.files.length === 0 || facialImageInput.files[0].size === 0) {
                    isValid = false;
                    facialImageStatus.textContent = 'Face scan is required.';
                    facialImageStatus.style.color = 'var(--danger)';
                    errors.push("Face Scan is required. Please capture a photo.");
                }


                if (!isValid) {
                    showAppMessage(errors.join('<br>'), 'error'); // Display all errors
                    e.preventDefault();
                    return;
                }
            });

            openFaceScanModalBtn.addEventListener('click', async function() {
                facialRecognitionModal.classList.add('show');
                await resetModal(); // Ensure modal is reset before starting camera

                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480 } });
                    cameraFeedVideo.srcObject = stream;
                    cameraFeedVideo.style.display = 'block';
                    // faceBoundingBoxCanvas.style.display = 'none'; // Hide if not using a detection library
                    faceScanMessage.textContent = 'Camera active. Position your face in the frame.';

                    cameraFeedVideo.onloadedmetadata = () => {
                        cameraFeedVideo.play();
                        // faceBoundingBoxCanvas.width = cameraFeedVideo.videoWidth; // Not needed
                        // faceBoundingBoxCanvas.height = cameraFeedVideo.videoHeight; // Not needed
                        captureCanvas.width = cameraFeedVideo.videoWidth;
                        captureCanvas.height = cameraFeedVideo.videoHeight;
                    };

                } catch (err) {
                    console.error('Error accessing camera:', err);
                    faceScanMessage.textContent = 'Could not access camera. Please allow permissions.';
                    faceScanMessage.style.color = 'var(--danger)';
                    capturePhotoBtn.disabled = true;
                    showAppMessage('Camera access denied or not available. Please ensure your camera is connected and not in use by another application.', 'danger');
                }
            });

            capturePhotoBtn.addEventListener('click', async function() {
                if (!stream || cameraFeedVideo.paused) {
                    faceScanMessage.textContent = 'Camera not active. Please retry.';
                    recognitionResultDiv.className = 'recognition-result danger';
                    return;
                }

                captureCtx.drawImage(cameraFeedVideo, 0, 0, captureCanvas.width, captureCanvas.height);
                capturedImageDataUrl = captureCanvas.toDataURL('image/png'); // Get image as Base64

                photoCaptured = true;
                cameraFeedVideo.pause();
                cameraFeedVideo.style.display = 'none'; // Hide video feed
                captureCanvas.style.display = 'block'; // Show captured image
                // faceBoundingBoxCanvas.style.display = 'none'; // Hide if not using a detection library
                // canvasCtx.clearRect(0, 0, faceBoundingBoxCanvas.width, faceBoundingBoxCanvas.height); // Not needed

                faceScanMessage.textContent = 'Photo captured! Review the image below.';

                capturePhotoBtn.style.display = 'none';
                retakePhotoBtn.style.display = 'inline-flex';
                recognitionResultDiv.textContent = 'Image captured. It will be processed upon submission.';
                recognitionResultDiv.className = 'recognition-result info';

                // Set facialImage input with the captured photo
                try {
                    const response = await fetch(capturedImageDataUrl);
                    const blob = await response.blob();
                    const file = new File([blob], 'captured_face.png', { type: 'image/png' });
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    facialImageInput.files = dataTransfer.files;
                    facialImageStatus.textContent = 'Face scan captured. Ready for submission.';
                    facialImageStatus.style.color = 'var(--success)';
                    facialRecognitionAttemptedInput.value = 'true';
                } catch (error) {
                    console.error('Error setting captured image to file input:', error);
                    facialImageStatus.textContent = 'Error capturing face scan.';
                    facialImageStatus.style.color = 'var(--danger)';
                    facialRecognitionAttemptedInput.value = 'false';
                }
            });

            retakePhotoBtn.addEventListener('click', function() {
                // When retaking, clear the file input and reset status
                facialImageInput.value = '';
                facialImageInput.files = new DataTransfer().files;
                facialImageStatus.textContent = '';
                facialRecognitionAttemptedInput.value = 'false'; // Reset to false when retaking
                resetModal();
                openFaceScanModalBtn.click(); // Re-trigger modal open and camera
            });

            closeModalBtn.addEventListener('click', function() {
                facialRecognitionModal.classList.remove('show');
                // Don't reset everything if user just closes. Keep the captured image if one exists.
                if (!photoCaptured) { // Only reset fully if no photo was captured
                   resetModal();
                } else {
                    if (stream) {
                        stream.getTracks().forEach(track => track.stop());
                        cameraFeedVideo.srcObject = null;
                    }
                    cameraFeedVideo.style.display = 'none';
                    // faceBoundingBoxCanvas.style.display = 'none'; // Not needed after close
                    // Keep the captured image in facialImageInput
                    // It will be hidden on next modal open by resetModal().
                }
            });

            // Initial flash message display (from PHP session)
            const flashMessage = document.querySelector('.message.show');
            if (flashMessage) {
                setTimeout(() => {
                    flashMessage.classList.remove('show');
                }, 5000);
            }

            const formContainer = document.querySelector('.form-container');
            if (formContainer) {
                formContainer.style.opacity = '0';
                formContainer.style.transform = 'translateY(30px)';
                setTimeout(() => {
                    formContainer.style.transition = 'all 0.6s ease';
                    formContainer.style.opacity = '1';
                    formContainer.style.transform = 'translateY(0)';
                }, 100);
            }

            const formInputs = document.querySelectorAll('.form-group input, .form-group select');
            formInputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.closest('.form-group').style.transform = 'translateY(-2px)';
                });

                input.addEventListener('blur', function() {
                    this.closest('.form-group').style.transform = 'translateY(0)';
                });
            });

            document.querySelectorAll('.sidebar nav li').forEach(li => {
                if (li.textContent.includes("Visitor Registration")) {
                    li.classList.add('active');
                } else {
                    li.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>