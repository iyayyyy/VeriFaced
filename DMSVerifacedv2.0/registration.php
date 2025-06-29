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

// Basic authentication check: Redirect if the admin is not logged in.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Set a flash message to inform the user why they were redirected.
    $_SESSION['flash_message'] = "Please log in to access this page.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: adminlogin.php"); // Redirect to your admin login page.
    exit(); // Important: Always call exit() after a header redirect.
}

// Initialize variables for flash messages
$flash_message = '';
$flash_message_type = '';

// Define the base directory for known faces.
// Assuming this script is in C:\xampp\htdocs\DMSVerifacedv5\DMSVerifaced5
// The target is C:\xampp\htdocs\DMSVerifacedv5\DMSVerifaced5\models\known_faces
// So, we can use a relative path from this script's directory.
$knownFacesBaseDir = __DIR__ . '/models/known_faces/'; // Using __DIR__ for absolute path safety

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Retrieve and sanitize form inputs
    $firstName = trim($_POST['firstName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirmPassword'] ?? '';
    $emailAddress = filter_var(trim($_POST['emailAddress'] ?? ''), FILTER_SANITIZE_EMAIL);
    $phoneNumber = trim($_POST['phoneNumber'] ?? '');
    $selectedUnitId = trim($_POST['unitNumber'] ?? ''); // This will be unit_id from the dropdown

    $errors = [];

    // Log the received POST data for debugging
    error_log("POST Data: " . print_r($_POST, true));
    error_log("FILES Data: " . print_r($_FILES, true));

    // Form validation
    if (empty($firstName)) {
        $errors[] = "First Name is required.";
    }
    if (empty($lastName)) {
        $errors[] = "Last Name is required.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    }
    if ($password !== $confirmPassword) {
        $errors[] = "Passwords do not match.";
    }
    // Password complexity validation
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter, one uppercase letter, one number, and one special character.";
    }

    if (empty($emailAddress) || !filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid Email Address is required.";
    }
    if (empty($phoneNumber)) {
        $errors[] = "Phone Number is required.";
    }
    if (empty($selectedUnitId)) { // This validates if an option was selected
        $errors[] = "Unit Number must be selected.";
    } else {
        // Server-side check: Is the selected unit already occupied?
        $stmt_check_unit = null;
        try {
            if (isset($conn) && $conn instanceof mysqli) {
                // Check if the selected unit_id is already assigned to a tenant
                $sql_check_unit = "SELECT t.tenant_id FROM tenants t WHERE t.unit_id = ?";
                $stmt_check_unit = $conn->prepare($sql_check_unit);
                if ($stmt_check_unit) {
                    $stmt_check_unit->bind_param("i", $selectedUnitId);
                    $stmt_check_unit->execute();
                    $result_check_unit = $stmt_check_unit->get_result();
                    if ($result_check_unit->num_rows > 0) {
                        $errors[] = "The selected Unit is already occupied. Please choose another unit.";
                    }
                } else {
                    error_log("Failed to prepare unit availability check statement: " . $conn->error);
                    $errors[] = "Database error during unit availability check.";
                }
            } else {
                $errors[] = "Database connection not available for unit availability check.";
            }
        } catch (Throwable $e) {
            error_log("Error during unit availability check: " . $e->getMessage());
            $errors[] = "An unexpected error occurred during unit availability check.";
        } finally {
            if ($stmt_check_unit) {
                $stmt_check_unit->close();
            }
        }
    }

    // Paths for the 3 known face images
    $knownFacePaths = [null, null, null];
    $unit_folder_name = null; // Holds the folder name for the unit

    // Only proceed with image upload preparation if no errors so far AND a unit is selected
    if (empty($errors) && !empty($selectedUnitId)) {
        // --- START: Fetch unit number and create folder name ---
        $stmt_get_unit_number = null;
        try {
            if (isset($conn) && $conn instanceof mysqli) {
                $sql_get_unit_number = "SELECT unit_number FROM units WHERE unit_id = ?";
                $stmt_get_unit_number = $conn->prepare($sql_get_unit_number);
                if ($stmt_get_unit_number) {
                    $stmt_get_unit_number->bind_param("i", $selectedUnitId);
                    $stmt_get_unit_number->execute();
                    $result_unit_number = $stmt_get_unit_number->get_result();
                    if ($result_unit_number->num_rows > 0) {
                        $row = $result_unit_number->fetch_assoc();
                        // Sanitize unit number for use as folder name (e.g., "Unit 1" becomes "Unit_1")
                        // This directly uses the unit_number from the database for the folder name.
                        $unit_folder_name = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $row['unit_number']));
                        error_log("Determined unit_folder_name for known faces: " . $unit_folder_name);
                    } else {
                        $errors[] = "Selected unit not found in database.";
                        error_log("Error: Selected unit_id {$selectedUnitId} not found in units table.");
                    }
                } else {
                    error_log("Failed to prepare statement for getting unit number: " . $conn->error);
                    $errors[] = "Database error during unit number retrieval.";
                }
            } else {
                $errors[] = "Database connection not available for unit number retrieval.";
            }
        } catch (Throwable $e) {
            error_log("Error fetching unit number for known faces folder: " . $e->getMessage());
            $errors[] = "An unexpected error occurred during unit number retrieval.";
        } finally {
            if ($stmt_get_unit_number) {
                $stmt_get_unit_number->close();
            }
        }
        // --- END: Fetch unit number and create folder name ---

        // Proceed with file upload ONLY if no new errors and unit folder name is found
        if (empty($errors) && $unit_folder_name !== null) {
            // Create tenant-specific directory inside known_faces
            $tenantKnownFacesDir = $knownFacesBaseDir . $unit_folder_name . '/';

            // IMPORTANT: Removed the mkdir() logic here.
            // We now assume the directory already exists and is writable.
            // You MUST ensure these directories are pre-created and have correct permissions (e.g., 0755 or 0777 for testing)
            // for the web server to write to them.

            // If directory creation was successful or it already exists (now, just check existence)
            if (is_dir($tenantKnownFacesDir)) { // Keep this check to ensure the target directory exists
                $fileInputNames = ['knownFace1', 'knownFace2', 'knownFace3'];
                // Only allow JPG/JPEG files as per the requested output naming
                $allowedfileExtensions = ['jpg', 'jpeg'];

                for ($i = 0; $i < count($fileInputNames); $i++) {
                    $inputName = $fileInputNames[$i];
                    if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] == UPLOAD_ERR_OK) {
                        $fileTmpPath = $_FILES[$inputName]['tmp_name'];
                        $fileName = $_FILES[$inputName]['name'];
                        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                        if (in_array($fileExtension, $allowedfileExtensions)) {
                            // Rename files to 1.jpg, 2.jpg, 3.jpg as requested
                            $newFileName = ($i + 1) . '.jpg';
                            $destPath = $tenantKnownFacesDir . $newFileName;

                            error_log("Attempting to move uploaded file {$inputName} to: " . $destPath);

                            if (move_uploaded_file($fileTmpPath, $destPath)) {
                                // Store the relative path for database (e.g., models/known_faces/Unit_1/1.jpg)
                                // This path should be accessible by your web server if face recognition models need it.
                                $relativeDbPath = 'models/known_faces/' . $unit_folder_name . '/' . $newFileName;
                                $knownFacePaths[$i] = $relativeDbPath;
                                error_log("File {$inputName} successfully moved to: " . $knownFacePaths[$i]);
                            } else {
                                $errors[] = "There was an error moving uploaded file " . ($i + 1) . ". Check server logs and directory permissions for {$tenantKnownFacesDir}."; // More specific error
                                error_log("Error moving uploaded file {$inputName}. PHP Error Code: " . $_FILES[$inputName]['error']);
                            }
                        } else {
                            $errors[] = "Invalid file type for file " . ($i + 1) . ". Only JPG/JPEG files are allowed.";
                            error_log("Invalid file extension uploaded for {$inputName}: " . $fileExtension);
                        }
                    } else if (isset($_FILES[$inputName]) && $_FILES[$inputName]['error'] == UPLOAD_ERR_NO_FILE) {
                        $errors[] = "File " . ($i + 1) . " is required.";
                    } else {
                        // Handle other specific upload errors for each file
                        switch ($_FILES[$inputName]['error']) {
                            case UPLOAD_ERR_INI_SIZE:
                            case UPLOAD_ERR_FORM_SIZE:
                                $errors[] = "Uploaded file " . ($i + 1) . " exceeds maximum size.";
                                break;
                            case UPLOAD_ERR_PARTIAL:
                                $errors[] = "File " . ($i + 1) . " upload was interrupted.";
                                break;
                            case UPLOAD_ERR_NO_TMP_DIR:
                                $errors[] = "Missing temporary folder for file " . ($i + 1) . " upload.";
                                break;
                            case UPLOAD_ERR_CANT_WRITE:
                                $errors[] = "Failed to write file " . ($i + 1) . " to disk. Check permissions for {$tenantKnownFacesDir}."; // More specific error
                                break;
                            case UPLOAD_ERR_EXTENSION:
                                $errors[] = "A PHP extension stopped the upload for file " . ($i + 1) . ".";
                                break;
                            default:
                                $errors[] = "Unknown upload error for file " . ($i + 1) . ". Code: " . $_FILES[$inputName]['error'];
                                break;
                        }
                    }
                }
            } else {
                // This branch is now hit if the pre-existing directory does NOT exist or is not readable
                $errors[] = "Critical: Cannot proceed with file uploads as the destination directory '{$tenantKnownFacesDir}' does not exist or is not accessible. Please ensure it is pre-created and writable.";
                error_log("Error: Target known_faces directory '{$tenantKnownFacesDir}' does not exist or is not accessible.");
            }
        } else if ($unit_folder_name === null) {
            error_log("Image upload skipped because unit_folder_name is null.");
        }
    }


    // Only proceed if no validation errors so far (including potential new errors from image upload or unit number retrieval)
    if (empty($errors)) {
        // --- DATABASE INSERTION STARTS HERE ---
        // Assume $conn is your database connection object from db_connect.php
        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = null;
            try {
                // Hash the password before storing it
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // SQL to insert new account (tenant)
                // Using $selectedUnitId for the unit_id column (which is now the actual unit_id from DB)
                // Added 3 new columns for known face paths
                $sql = "INSERT INTO tenants (first_name, last_name, password_hash, email, phone_number, unit_id, known_face_path_1, known_face_path_2, known_face_path_3) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    // 'sssssiss' corresponds to string, string, string, string, string, integer, string, string, string
                    $stmt->bind_param("sssssisss",
                        $firstName,
                        $lastName,
                        $hashedPassword,
                        $emailAddress,
                        $phoneNumber,
                        $selectedUnitId,
                        $knownFacePaths[0],
                        $knownFacePaths[1],
                        $knownFacePaths[2]
                    );

                    if ($stmt->execute()) {
                        $_SESSION['flash_message'] = "Account registered successfully for {$firstName} {$lastName}!";
                        $_SESSION['flash_message_type'] = "success";
                        header("Location: index.php"); // Redirect to unit profiles page (admin dashboard)
                        exit();
                    } else {
                        // Check for duplicate entry error specifically (e.g., if email is unique)
                        if ($conn->errno == 1062) { // MySQL error code for duplicate entry
                            $_SESSION['flash_message'] = "Registration failed: Email address is already in use.";
                        } else {
                            $_SESSION['flash_message'] = "Error registering account: " . $stmt->error;
                        }
                        $_SESSION['flash_message_type'] = "danger";
                        error_log("Error inserting new tenant: " . $stmt->error);
                    }
                } else {
                    $_SESSION['flash_message'] = "Database error: Could not prepare statement for registration.";
                    $_SESSION['flash_message_type'] = "danger";
                    error_log("Failed to prepare statement for tenant registration: " . $conn->error);
                }
            } catch (Throwable $e) {
                error_log("Registration error (DB insert): " . $e->getMessage());
                $_SESSION['flash_message'] = "An unexpected error occurred during registration. Please try again later.";
                $_SESSION['flash_message_type'] = "danger";
            } finally {
                if ($stmt) {
                    $stmt->close();
                }
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Please try again later.";
            $_SESSION['flash_message_type'] = "danger";
            error_log("Database connection variable \$conn is not set or not a mysqli object in registration.php.");
        }
        // --- DATABASE INSERTION ENDS HERE ---
    } else {
        // If there are validation errors, store them in a flash message
        $_SESSION['flash_message'] = implode("<br>", $errors);
        $_SESSION['flash_message_type'] = "danger";
    }
    // Reload the page to display flash messages (Post/Redirect/Get pattern)
    header("Location: registration.php");
    exit();
}

// After POST handling, retrieve flash messages if any for display on GET request
if (isset($_SESSION['flash_message'])) {
    $flash_message = e($_SESSION['flash_message']);
    $flash_message_type = e($_SESSION['flash_message_type'] ?? 'info');
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// --- Dynamic Unit Number Dropdown Population with Availability Check ---
$unit_options_html = '<option value="">Select Unit...</option>'; // Only one initial option here
if (isset($conn) && $conn instanceof mysqli) {
    try {
        // Select all units and left join with tenants to find occupied units
        // Order numerically based on the number in 'Unit X'
        $sql_units = "SELECT u.unit_id, u.unit_number, t.tenant_id
                      FROM units u
                      LEFT JOIN tenants t ON u.unit_id = t.unit_id
                      ORDER BY CAST(SUBSTRING_INDEX(u.unit_number, ' ', -1) AS UNSIGNED) ASC";

        $result_units = $conn->query($sql_units);

        if ($result_units) {
            if ($result_units->num_rows > 0) {
                while ($row_unit = $result_units->fetch_assoc()) {
                    $unit_display_text = e($row_unit['unit_number']);
                    $option_value = e($row_unit['unit_id']); // Value for backend will be unit_id

                    $disabled_attr = '';
                    $availability_text = '';

                    // If tenant_id is not NULL, it means the unit is occupied
                    if ($row_unit['tenant_id'] !== null) {
                        $availability_text = " (Unavailable)";
                        $disabled_attr = 'disabled';
                    }

                    $unit_options_html .= "<option value='{$option_value}' {$disabled_attr}>{$unit_display_text}{$availability_text}</option>";
                }
                $result_units->free();
            } else {
                $unit_options_html .= "<option value='' disabled>No units available in database.</option>";
                error_log("No units found in units table for registration dropdown.");
            }
        } else {
            $unit_options_html .= "<option value='' disabled>Error loading units from database.</option>";
            error_log("Error executing query for units dropdown: " . $conn->error);
        }
    } catch (Throwable $e) {
        $unit_options_html .= "<option value='' disabled>An unexpected error occurred loading units.</option>";
        error_log("Error populating units dropdown in registration.php: " . $e->getMessage());
    }
} else {
    $unit_options_html .= "<option value='' disabled>Database connection error for units.</option>";
    error_log("Database connection not available for unit dropdown in registration.php.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced - Account Registration</title>
    <style>
        /* Your CSS (unchanged) */
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
            /* REMOVED: display: grid; */
            /* REMOVED: grid-template-columns: 280px 1fr; */
            width: 100vw;
            /* REMOVED: min-height: 100vh; */
            display: flex; /* Added */
        }

        .sidebar {
            /* REMOVED: grid-column: 1 / 2; */
            width: 280px;
            background: linear-gradient(180deg, #8B7D6B 0%, #6B5B4F 100%); /* Rich khaki gradient */
            color: #FFFFFF;
            padding: 30px 25px;
            display: flex;
            flex-direction: column;
            height: 100vh; /* Changed to height */
            overflow-y: auto; /* Changed to auto for potential vertical scrolling */
            overflow-x: hidden; /* Added to remove scrollbar as requested */
            box-sizing: border-box;
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
            position: fixed; /* Changed to fixed */
            top: 0; /* Added */
            left: 0; /* Added */
            align-items: center;
            transition: transform 0.3s ease-in-out; /* For responsive slide effect */
            z-index: 1000; /* Ensure sidebar is on top */
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
            width: 100%; /* Ensure the ul also takes full width */
        }

        .sidebar nav ul li {
            margin-bottom: 8px;
            width: 100%; /* Ensure li takes full width */
        }

        .sidebar nav ul li a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 1em;
            font-weight: 500;
            display: flex;
            align-items: center;
            padding: 14px 25px; /* Increased horizontal padding to fill the sidebar */
            width: 100%; /* Ensure the anchor tag covers the full width of its parent li */
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-sizing: border-box; /* Important: Include padding in width calculation */
        }

        .sidebar nav ul li a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent);
            transition: left 0.5s;
        }

        .sidebar nav ul li a:hover::before {
            left: 100%;
        }

        .sidebar nav ul li.active a,
        .sidebar nav ul li a:hover {
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%);
            color: #FFFFFF;
            transform: translateX(0); /* Changed to 0 to prevent visual shift when background extends */
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .sidebar nav ul li.active a .icon,
        .sidebar nav ul li a:hover .icon {
            opacity: 1;
            transform: scale(1.1);
        }

        .logout-btn {
            margin-top: auto;
            background: linear-gradient(135deg, #D2691E 0%, #B8860B 100%);
            color: #FFFFFF;
            border: 2px solid rgba(255,255,255,0.2);
            padding: 12px 20px;
            width: 85%;
            max-width: 220px;
            cursor: pointer;
            border-radius: 25px;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: block;
            text-align: center;
            margin-left: auto;
            margin-right: auto;
            position: relative;
            z-index: 1;
            overflow: hidden;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
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

        /* Main Content Area - new wrapper */
        .content-area {
            /* REMOVED: grid-column: 2 / 3; */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            position: relative; /* For the menu toggle */
            margin-left: 280px; /* Added to offset the fixed sidebar */
            flex-grow: 1; /* Allow content to take remaining space */
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

        /* Unit Profiles Page Specific Styles */
        .tenant-profile-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12), 0 4px 10px rgba(107, 91, 79, 0.06);
            display: flex;
            align-items: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
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
        }

        .profile-pic-small {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 18px;
            border: 2px solid #D2B48C;
            box-shadow: 0 3px 8px rgba(107, 91, 79, 0.15);
        }

        .tenant-info {
            flex-grow: 1;
        }

        .tenant-info p {
            margin: 6px 0;
            font-size: 0.95rem;
            color: #4A4A4A;
            font-weight: 400;
        }

        .tenant-info p strong {
            color: #6B5B4F;
            font-weight: 600;
            min-width: 80px;
            display: inline-block;
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

        .tenant-actions {
            margin-left: auto;
            display: flex;
            gap: 12px;
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

        /* Form Styles */
         #registrationForm {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12), 0 4px 10px rgba(107, 91, 79, 0.06);
            max-width: 700px; /* Adjusted max-width for better fit with fixed sidebar */
            margin-left: auto;
            margin-right: auto;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -8px;
            margin-right: -8px;
            margin-bottom: 12px;
        }

        .form-group {
            flex: 1 1 calc(50% - 16px);
            min-width: 200px;
            display: flex;
            flex-direction: column;
            margin: 0 8px 16px 8px;
        }

        .form-group label {
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #6B5B4F;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="file"],
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 2px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 8px;
            font-size: 0.9rem;
            box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 1px 3px rgba(107, 91, 79, 0.05);
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
            cursor: pointer;
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
        }

        .tenant-info-card .info {
            flex-grow: 1;
        }

        .tenant-info-card .info p {
            margin: 8px 0;
            font-size: 1.05rem;
            color: #4A4A4A;
        }

        .tenant-info-card .info p strong {
            color: #6B5B4F;
            font-weight: 600;
            min-width: 140px;
            display: inline-block;
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

        .member-card, .visitor-card {
            display: flex;
            align-items: center;
            padding: 18px 0;
            border-bottom: 2px solid rgba(230, 220, 198, 0.5);
            color: #4A4A4A;
            transition: all 0.3s ease;
        }

        .member-card:last-child, .visitor-card:last-child {
            border-bottom: none;
        }

        .member-card:hover, .visitor-card:hover {
            background: linear-gradient(135deg, rgba(210, 180, 140, 0.05) 0%, rgba(230, 220, 198, 0.05) 100%);
            padding-left: 10px;
            border-radius: 8px;
        }

        .member-card .info p, .visitor-card .info p {
            margin: 4px 0;
            font-size: 0.95rem;
        }

        .member-card .info p strong, .visitor-card .info p strong {
            color: #6B5B4F;
            font-weight: 600;
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
            padding: 18px 24px;
            border-radius: 12px;
            margin: 0 0 25px 0;
            font-weight: 600;
            opacity: 1;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
            box-sizing: border-box;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            position: relative;
            overflow: hidden;
        }

        .flash-message::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: currentColor;
            opacity: 0.8;
        }

        .flash-message.success {
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
            border-color: #B8860B;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .flash-message.danger {
            background: linear-gradient(135deg, #CD853F 0%, #A0522D 100%);
            border-color: #A0522D;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .flash-message.warning {
            background: linear-gradient(135deg, #F0E68C 0%, #DDD8B8 100%);
            border-color: #D2B48C;
            color: #6B5B4F;
        }

        .flash-message.info {
            background: linear-gradient(135deg, #E6DCC6 0%, #D2B48C 100%);
            border-color: #B8860B;
            color: #6B5B4F;
        }

        /* Responsive Design */
        /* Hamburger Menu Toggle */
        .menu-toggle {
            display: none; /* Hidden by default on large screens */
            flex-direction: column;
            justify-content: space-around;
            width: 30px;
            height: 25px;
            cursor: pointer;
            position: absolute;
            top: 30px;
            right: 25px;
            z-index: 1100; /* Above sidebar and content */
            padding: 5px;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .menu-toggle div {
            width: 100%;
            height: 3px;
            background-color: #6B5B4F; /* Dark khaki color */
            border-radius: 10px;
            transition: all 0.3s linear;
            transform-origin: 1px;
        }

        .menu-toggle.active div:nth-child(1) {
            transform: rotate(45deg);
        }

        .menu-toggle.active div:nth-child(2) {
            opacity: 0;
            transform: translateX(-100%);
        }

        .menu-toggle.active div:nth-child(3) {
            transform: rotate(-45deg);
        }

        /* Overlay for mobile when sidebar is open */
        .overlay {
            display: none; /* Hidden by default */
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999; /* Below sidebar, above content */
            cursor: pointer;
        }

        body.sidebar-active .overlay {
            display: block; /* Show when sidebar is active */
        }


        @media (max-width: 1024px) {
            .container {
                /* REMOVED: grid-template-columns: 1fr; */ /* Single column layout handled by flex-direction */
                flex-direction: column; /* Added */
            }

            .sidebar {
                position: fixed;
                left: -280px; /* Hide sidebar off-screen */
                height: 100vh;
                overflow-y: auto;
                box-shadow: 0 0 20px rgba(0,0,0,0.25);
            }

            .sidebar.active {
                transform: translateX(280px); /* Slide sidebar into view */
            }

            .menu-toggle {
                display: flex; /* Show hamburger icon */
            }

            .content-area {
                /* REMOVED: grid-column: 1 / 2; */ /* Occupy the full width */
                margin-left: 0; /* REMOVED: margin-left from fixed sidebar for mobile */
                width: 100%; /* ENSURE FULL WIDTH */
            }

            .main-content {
                padding-top: 80px; /* Make space for the menu toggle */
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
        }

        @media (max-width: 992px) {
            .form-group {
                flex: 1 1 calc(100% - 24px);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 240px;
                padding: 20px;
                left: -240px; /* Adjust hide position for smaller sidebar */
            }
            .sidebar.active {
                transform: translateX(240px); /* Adjust slide position */
            }

            .sidebar .logo {
                font-size: 1.6em;
                margin-bottom: 25px;
            }

            .sidebar nav ul li a {
                padding: 12px 15px;
                font-size: 0.95em;
            }

            .main-content {
                padding: 25px;
                padding-top: 70px; /* Adjust padding for menu toggle */
            }

            .tenant-profile-card, .tenant-info-card {
                flex-direction: column;
                align-items: flex-start;
                padding: 20px;
            }

            .profile-pic, .profile-pic-large, .profile-pic-small {
                margin-right: 0;
                margin-bottom: 15px;
                align-self: center;
            }

            .tenant-actions {
                width: 100%;
                margin-left: 0;
                margin-top: 20px;
                justify-content: center;
            }

            .tenant-actions button {
                flex-grow: 1;
                max-width: 140px;
            }

            .form-actions {
                text-align: center;
            }

            .tabs {
                flex-wrap: wrap;
                padding: 5px;
            }

            .tab-button {
                flex-basis: 50%;
                text-align: center;
                font-size: 0.95rem;
                padding: 12px 16px;
            }
            .flash-message {
                padding: 14px 20px;
                font-size: 0.9rem;
                margin: 0 0 20px 0;
                border-radius: 6px;
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
                    font-size: 1.0rem;
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
                .flash-message .btn-close,
                .logout-modal-overlay,
                .logout-modal-content {
                    transition: none;
                }

                .flash-message:hover {
                    transform: none;
                }
            }
        }
        /* Modal for messages - new styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 2000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(5px); /* Blurred background */
        }

        .modal-content {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            margin: auto;
            padding: 30px;
            border: 1px solid #E6DCC6;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(107, 91, 79, 0.18), 0 4px 10px rgba(107, 91, 79, 0.08);
            max-width: 500px;
            width: 90%;
            text-align: center;
            position: relative;
            transform: translateY(-20px);
            opacity: 0;
            animation: fadeInModal 0.3s forwards cubic-bezier(0.4, 0, 0.2, 1);
        }

        @keyframes fadeInModal {
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #D2B48C;
            padding-bottom: 10px;
        }

        .modal-header h3 {
            margin: 0;
            color: #6B5B4F;
            font-size: 1.5em;
            font-weight: 700;
        }

        .close-button {
            color: #8B7D6B;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .close-button:hover,
        .close-button:focus {
            color: #A0522D;
        }

        .modal-body {
            font-size: 1.05em;
            color: #4A4A4A;
            line-height: 1.6;
            margin-bottom: 25px;
            text-align: left;
        }

        .modal-body ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .modal-body ul li {
            margin-bottom: 8px;
            padding-left: 20px;
            position: relative;
        }

        .modal-body ul li::before {
            content: '•'; /* Bullet point */
            color: #B8860B; /* Khaki color */
            position: absolute;
            left: 0;
            top: 0;
            font-size: 1.2em;
            line-height: 1;
        }

        .modal-footer {
            text-align: center;
        }

        .modal-footer button {
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 25px;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(184, 134, 11, 0.3);
        }

        .modal-footer button:hover {
            background: linear-gradient(135deg, #B8860B 0%, #DAA520 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(184, 134, 11, 0.4);
        }
    </style>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">Verifaced</div>
            <nav>
                <ul>
                    <li><a href="index.php"> Unit Profiles</a></li>
                    <li class="active"><a href="registration.php"> Account Registration</a></li>
                </ul>
            </nav>
            <button class="logout-btn">Log out</button>
        </aside>
        <div class="content-area">
             <div class="menu-toggle" id="menuToggle">
                <div></div>
                <div></div>
                <div></div>
            </div>
            <main class="main-content">
                <div id="flashMessageContainer">
                    <?php
                    // Display flash messages if they were set by the POST request or previous page
                    if ($flash_message) {
                        echo "<div class='flash-message {$flash_message_type}'>{$flash_message}</div>";
                    }
                    ?>
                </div>
                <h2>Account Registration</h2>
                <form id="registrationForm" method="POST" action="registration.php" enctype="multipart/form-data">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="firstName">First Name</label>
                            <input type="text" id="firstName" name="firstName" required>
                        </div>
                        <div class="form-group">
                            <label for="lastName">Last Name</label>
                            <input type="text" id="lastName" name="lastName" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirmPassword">Confirm Password</label>
                            <input type="password" id="confirmPassword" name="confirmPassword" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="emailAddress">Email Address</label>
                            <input type="email" id="emailAddress" name="emailAddress" required>
                        </div>
                        <div class="form-group">
                            <label for="phoneNumber">Phone Number</label>
                            <input type="tel" id="phoneNumber" name="phoneNumber" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="unitNumber">Unit Number</label>
                            <select id="unitNumber" name="unitNumber" required>
                                <?php echo $unit_options_html; // Dynamically populated from database with availability ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="knownFace1">Face Image 1</label>
                            <input type="file" id="knownFace1" name="knownFace1" accept="image/jpeg" required>
                        </div>
                        <div class="form-group">
                            <label for="knownFace2">Face Image 2</label>
                            <input type="file" id="knownFace2" name="knownFace2" accept="image/jpeg" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="knownFace3">Face Image 3</label>
                            <input type="file" id="knownFace3" name="knownFace3" accept="image/jpeg" required>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="done-btn">Done</button>
                    </div>
                </form>
            </main>
        </div>
        <div class="overlay" id="overlay"></div>
    </div>

    <!-- Validation Modal (existing) -->
    <div id="myModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Validation Error</h3>
                <span class="close-button">&times;</span>
            </div>
            <div class="modal-body">
                <ul id="modalMessageList">
                    </ul>
            </div>
            <div class="modal-footer">
                <button class="close-button">OK</button>
            </div>
        </div>
    </div>

    <!-- Custom Logout Confirmation Modal -->
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

    <script src="app.js"></script>
    <script>
        // Get the modal and close buttons
        const modal = document.getElementById('myModal');
        const closeButtons = modal.querySelectorAll('.close-button'); // This selects both 'x' and 'OK' buttons
        const menuToggle = document.getElementById('menuToggle'); // Added
        const sidebar = document.querySelector('.sidebar'); // Added
        const overlay = document.getElementById('overlay'); // Added
        const body = document.body; // Added

        // Custom logout modal functionality
        function showLogoutModal() {
            const logoutModal = document.getElementById('logoutModal');
            const confirmBtn = logoutModal.querySelector('.logout-modal-btn-confirm');
            const cancelBtn = logoutModal.querySelector('.logout-modal-btn-cancel');
            const closeBtn = logoutModal.querySelector('.logout-modal-close');
            
            // Show modal with animation
            logoutModal.classList.add('show');
            
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
                logoutModal.classList.remove('show');
                // Remove event listeners to prevent memory leaks
                confirmBtn.removeEventListener('click', handleConfirm);
                cancelBtn.removeEventListener('click', handleCancel);
                closeBtn.removeEventListener('click', handleCancel);
                logoutModal.removeEventListener('click', handleOverlayClick);
                document.removeEventListener('keydown', handleEscape);
            };
            
            // Handle clicking outside modal
            const handleOverlayClick = (e) => {
                if (e.target === logoutModal) {
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
            logoutModal.addEventListener('click', handleOverlayClick);
            document.addEventListener('keydown', handleEscape);
        }

        // Function to show custom modal messages (for validation errors)
        function showModal(title, messages, isLogoutConfirm = false) {
            const modalTitle = document.getElementById('modalTitle');
            const modalMessageList = document.getElementById('modalMessageList');
            const modalFooter = modal.querySelector('.modal-footer');

            modalTitle.textContent = title; // Set the title
            modalMessageList.innerHTML = ''; // Clear previous messages

            if (Array.isArray(messages)) {
                messages.forEach(msg => {
                    const li = document.createElement('li');
                    li.textContent = msg;
                    modalMessageList.appendChild(li);
                });
            } else {
                // If messages is a single string, display it directly
                const li = document.createElement('li');
                li.innerHTML = messages; // Use innerHTML to allow for <br> if needed
                modalMessageList.appendChild(li);
            }

            // Note: isLogoutConfirm is no longer used since we have separate logout modal
            // Default footer for general messages
            modalFooter.innerHTML = '<button class="close-button">OK</button>';
            modalFooter.querySelector('.close-button').addEventListener('click', () => modal.style.display = 'none');

            modal.style.display = 'flex'; // Show the modal
        }

        // Event listener for general close buttons (the 'x' and default 'OK')
        closeButtons.forEach(button => {
            button.addEventListener('click', () => {
                modal.style.display = 'none';
                // Restore default "OK" button if it was changed
                modal.querySelector('.modal-footer').innerHTML = '<button class="close-button">OK</button>';
                modal.querySelector('.close-button').addEventListener('click', () => modal.style.display = 'none');
            });
        });

        // Close the modal when the user clicks anywhere outside of the modal content
        window.addEventListener('click', (event) => {
            if (event.target == modal) {
                modal.style.display = 'none';
                // Restore default "OK" button if it was changed
                modal.querySelector('.modal-footer').innerHTML = '<button class="close-button">OK</button>';
                modal.querySelector('.close-button').addEventListener('click', () => modal.style.display = 'none');
            }
        });

        // ENHANCED: Client-side JavaScript for logout button with custom modal
        document.querySelector('.logout-btn').addEventListener('click', function(e) {
            e.preventDefault(); // Prevent any default action
            showLogoutModal();
        });

        // Toggle sidebar visibility
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

        // Basic client-side form validation for password match (server-side is essential!)
        document.getElementById('registrationForm').addEventListener('submit', function(event) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirmPassword').value;
            const emailAddress = document.getElementById('emailAddress').value;
            const phoneNumber = document.getElementById('phoneNumber').value;
            const unitSelect = document.getElementById('unitNumber'); // Get the select element
            const unitNumber = unitSelect.value; // Get its value

            const knownFace1 = document.getElementById('knownFace1');
            const knownFace2 = document.getElementById('knownFace2');
            const knownFace3 = document.getElementById('knownFace3');

            let isValid = true;
            let messages = [];

            // Reset custom validation styles for immediate visual feedback
            document.getElementById('firstName').style.borderColor = '';
            document.getElementById('lastName').style.borderColor = '';
            document.getElementById('password').style.borderColor = '';
            document.getElementById('confirmPassword').style.borderColor = '';
            document.getElementById('emailAddress').style.borderColor = '';
            document.getElementById('phoneNumber').style.borderColor = '';
            unitSelect.style.borderColor = ''; // Reset select border
            knownFace1.style.borderColor = '';
            knownFace2.style.borderColor = '';
            knownFace3.style.borderColor = '';

            // --- Basic Field Validation ---
            if (!document.getElementById('firstName').value.trim()) { messages.push('First Name is required.'); isValid = false; document.getElementById('firstName').style.borderColor = 'red'; }
            if (!document.getElementById('lastName').value.trim()) { messages.push('Last Name is required.'); isValid = false; document.getElementById('lastName').style.borderColor = 'red'; }
            if (emailAddress === null || emailAddress.trim() === '') { messages.push('Email Address is required.'); isValid = false; document.getElementById('emailAddress').style.borderColor = 'red'; }
            if (phoneNumber === null || phoneNumber.trim() === '') { messages.push('Phone Number is required.'); isValid = false; document.getElementById('phoneNumber').style.borderColor = 'red'; }
            if (!unitNumber) { messages.push('Unit Number must be selected.'); isValid = false; unitSelect.style.borderColor = 'red'; }


            // --- Password Validation ---
            if (!password) {
                messages.push('Password is required.');
                isValid = false;
                document.getElementById('password').style.borderColor = 'red';
            } else {
                if (password !== confirmPassword) {
                    messages.push('Passwords do not match.');
                    document.getElementById('confirmPassword').style.borderColor = 'red';
                    isValid = false;
                }
                // Password complexity validation
                const passwordRegex = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/; // At least one lowercase, one uppercase, one digit, one special char
                if (!passwordRegex.test(password)) {
                    messages.push('Password must be strong: at least one lowercase, one uppercase, one number, and one special character.');
                    document.getElementById('password').style.borderColor = 'red';
                    isValid = false;
                }
            }


            // --- Email Format Validation ---
            if (emailAddress && !/^\S+@\S+\.\S+$/.test(emailAddress)) {
                messages.push('Please enter a valid email address.');
                document.getElementById('emailAddress').style.borderColor = 'red';
                isValid = false;
            }

            // --- Phone Number Format Validation (exactly 11 digits) ---
            if (phoneNumber && !/^\d{11}$/.test(phoneNumber)) {
                messages.push('Phone number must be exactly 11 digits and contain only numbers.');
                document.getElementById('phoneNumber').style.borderColor = 'red';
                isValid = false;
            }

            // --- Unit Availability Validation (Client-Side Check for Disabled Option) ---
            const selectedOption = unitSelect.options[unitSelect.selectedIndex];
            if (selectedOption && selectedOption.disabled) {
                messages.push('The selected Unit is unavailable. Please choose another one.');
                unitSelect.style.borderColor = 'red';
                isValid = false;
            }

            // --- File Upload Validation (Client-side enforcement for .jpg/.jpeg) ---
            const allowedFileTypes = ['image/jpeg']; // Only JPEG MIME type for client-side

            if (knownFace1.files.length === 0) {
                messages.push('Please upload Known Face Image 1.');
                knownFace1.style.borderColor = 'red';
                isValid = false;
            } else if (!allowedFileTypes.includes(knownFace1.files[0].type)) {
                messages.push('Known Face Image 1 must be a JPG or JPEG file.');
                knownFace1.style.borderColor = 'red';
                isValid = false;
            }

            if (knownFace2.files.length === 0) {
                messages.push('Please upload Known Face Image 2.');
                knownFace2.style.borderColor = 'red';
                isValid = false;
            } else if (!allowedFileTypes.includes(knownFace2.files[0].type)) {
                messages.push('Known Face Image 2 must be a JPG or JPEG file.');
                knownFace2.style.borderColor = 'red';
                isValid = false;
            }

            if (knownFace3.files.length === 0) {
                messages.push('Please upload Known Face Image 3.');
                knownFace3.style.borderColor = 'red';
                isValid = false;
            } else if (!allowedFileTypes.includes(knownFace3.files[0].type)) {
                messages.push('Known Face Image 3 must be a JPG or JPEG file.');
                knownFace3.style.borderColor = 'red';
                isValid = false;
            }

            if (!isValid) {
                showModal("Validation Error", messages); // Display all accumulated messages using the custom modal
                event.preventDefault(); // Stop form submission
            }
        });
    </script>
</body>
</html>