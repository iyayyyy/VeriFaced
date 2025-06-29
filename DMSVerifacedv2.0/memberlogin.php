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

// Redirect if already logged in as admin, tenant, or added member
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header("Location: index.php");
    exit();
}
if (isset($_SESSION['tenant_logged_in']) && $_SESSION['tenant_logged_in'] === true) {
    header("Location: T_UnitProfile.php");
    exit();
}
if (isset($_SESSION['added_member_logged_in']) && $_SESSION['added_member_logged_in'] === true) {
    header("Location: M_Dashboard.php"); // Redirect to member dashboard
    exit();
}

// Handle form submission for member login
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = null;
    try {
        if (isset($conn) && $conn instanceof mysqli) {
            $member = null; // Initialize member variable

            // Query the addedmembers table
            $sql = "SELECT added_member_id, tenant_id, full_name, username, password_hash FROM addedmembers WHERE username = ? LIMIT 1";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $member = $result->fetch_assoc();

                    // Verify the password
                    if (password_verify($password, $member['password_hash'])) {
                        // Authentication successful
                        $_SESSION['added_member_logged_in'] = true;
                        $_SESSION['added_member_id'] = $member['added_member_id'];
                        $_SESSION['added_member_tenant_id'] = $member['tenant_id']; // Store the associated tenant_id
                        $_SESSION['added_member_full_name'] = $member['full_name'];
                        $_SESSION['added_member_username'] = $member['username'];

                        $_SESSION['flash_message'] = "Welcome, " . htmlspecialchars($member['full_name']) . "! You have logged in successfully as a member.";
                        $_SESSION['flash_message_type'] = "success";

                        header("Location: M_Dashboard.php"); // Redirect to member dashboard
                        exit();
                    } else {
                        // Password does not match
                        $_SESSION['flash_message'] = "Invalid username or password. Please try again.";
                        $_SESSION['flash_message_type'] = "danger";
                    }
                } else {
                    // No member found with that username
                    $_SESSION['flash_message'] = "Invalid username or password. Please try again.";
                    $_SESSION['flash_message_type'] = "danger";
                }
            } else {
                error_log("Failed to prepare statement for member login: " . $conn->error);
                $_SESSION['flash_message'] = "Database error during login. Please try again later.";
                $_SESSION['flash_message_type'] = "danger";
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Please try again later.";
            $_SESSION['flash_message_type'] = "danger";
            error_log("Database connection variable \$conn is not set or not a mysqli object in memberlogin.php.");
        }
    } catch (Throwable $e) {
        error_log("Member login error: " . $e->getMessage());
        $_SESSION['flash_message'] = "An unexpected error occurred. Please try again later.";
        $_SESSION['flash_message_type'] = "danger";
    } finally {
        if ($stmt) {
            $stmt->close();
        }
    }
    // Redirect to self to display flash messages (Post/Redirect/Get pattern)
    header("Location: memberlogin.php");
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced Member Login</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* Reusing CSS styles from login.php for consistency */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%);
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
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%);
            color: white;
            padding: 20px 40px;
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 24px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3);
            border-radius: 20px 20px 0 0;
            height: 80px;
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
            background: rgba(245, 245, 220, 0.5);
            padding: 100px 40px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
        }

        .left-section h1 {
            font-size: 48px;
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
            gap: 15px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 15px;
            color: #666;
            font-size: 16px;
            font-weight: 500;
        }

        .contact-icon {
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: white;
            box-shadow: 0 2px 10px rgba(107, 91, 79, 0.2);
        }

        .right-section {
            flex: 1;
            padding: 100px 40px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .login-form {
            background: #ffffff;
            border: 1px solid rgba(221, 221, 221, 0.5);
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
        }

        .form-header {
            margin-bottom: 30px;
            text-align: center;
        }

        .form-header h2 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #333;
        }

        .form-header p {
            font-size: 16px;
            color: #777;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 16px;
            color: #555;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            background: #fdfdfd;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #6B5B4F;
            box-shadow: 0 0 0 3px rgba(107, 91, 79, 0.2);
        }

        .form-group input::placeholder {
            color: #aaa;
        }

        .sign-in-btn, .alt-btn {
            width: 100%;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .sign-in-btn {
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3);
        }

        .sign-in-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107, 91, 79, 0.4);
        }

        .alt-btn { /* Using alt-btn for other links on this page */
            background: transparent;
            color: #6B5B4F;
            border: 2px solid #6B5B4F;
            margin-bottom: 20px;
        }

        .alt-btn:hover {
            background: #6B5B4F;
            color: white;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3);
            transform: translateY(-2px);
        }

        .other-links {
            text-align: center;
        }

        .other-links a {
            color: #6B5B4F;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s;
        }

        .other-links a:hover {
            text-decoration: underline;
            color: #8B7D6B;
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
            max-height: 100px;
            overflow: hidden;
        }

        .flash-message.success {
            background-color: #4CAF50;
            border-color: #4CAF50;
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

        @media (max-width: 992px) {
            .container {
                flex-direction: column;
                width: 95%;
                min-height: auto;
                height: auto;
            }

            .header {
                position: relative;
                border-radius: 20px 20px 0 0;
                height: auto;
                padding: 15px 20px;
                font-size: 20px;
            }

            .left-section, .right-section {
                padding: 30px 20px;
                text-align: center;
                align-items: center;
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
        }

        @media (max-width: 576px) {
            .login-form {
                padding: 25px;
            }

            .form-header h2 {
                font-size: 20px;
            }

            .form-header p {
                font-size: 14px;
            }

            .sign-in-btn, .alt-btn {
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
            <p>Secure your unit by accessing Verifaced. Members, please sign in here.</p>
            
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
                    <h2>Sign In as Member</h2>
                    <p>Use your username and password to log in.</p>
                </div>

                <?php
                // Display flash messages
                if ($flash_message) {
                    echo "<div class='flash-message {$flash_message_type}'>{$flash_message}</div>";
                }
                ?>

                <form method="POST" action="memberlogin.php">
                    <div class="form-group">
                        <label for="username">Username:</label>
                        <input type="text" id="username" name="username" placeholder="Enter your username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>

                    <button type="submit" class="sign-in-btn">Sign In</button>
                </form>

                <div class="other-links">
                    <a href="login.php">Back to Tenant/Main Login</a><br>
                    <a href="visitorform.php">Sign In as Visitor</a>
                </div>
            </div>
        </div>
    </div>
    <script>
        // Simple client-side flash message dismissal for this page
        document.addEventListener('DOMContentLoaded', function() {
            const flashMessageDiv = document.querySelector('.flash-message');
            if (flashMessageDiv) {
                setTimeout(() => {
                    flashMessageDiv.style.opacity = '0';
                    flashMessageDiv.style.maxHeight = '0';
                    flashMessageDiv.style.overflow = 'hidden';
                    flashMessageDiv.style.paddingTop = '0';
                    flashMessageDiv.style.paddingBottom = '0';
                    flashMessageDiv.style.marginTop = '0';
                    flashMessageDiv.style.marginBottom = '0';
                    setTimeout(() => {
                        flashMessageDiv.remove();
                    }, 500); // Match CSS transition duration
                }, 5000);
            }
        });
    </script>
</body>
</html>
