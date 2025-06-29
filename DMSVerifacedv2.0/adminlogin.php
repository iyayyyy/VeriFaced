<?php
// THIS MUST BE THE VERY FIRST THLINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Include your database connection file (e.g., 'db_connect.php')
require_once 'db_connect.php'; // Uncomment this line once your db_connect.php is ready

// Function to safely output HTML to prevent XSS (Cross-Site Scripting)
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if the form was submitted using the POST method
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $adminEmail = $_POST['adminEmail'] ?? '';
    $adminPassword = $_POST['adminPassword'] ?? '';

    // --- DATABASE INTEGRATION STARTS HERE ---
    // 1. Validate and Sanitize Input (Crucial for security)
    $adminEmail = filter_var($adminEmail, FILTER_SANITIZE_EMAIL);
    // You might want to add more robust validation here, e.g., for password strength

    $stmt = null; // Initialize $stmt to null for error handling
    try {
        // Assume $conn is your database connection object from db_connect.php or defined here
        if (isset($conn) && $conn instanceof mysqli) {
            // Prepare a SQL query to fetch admin user data
            // Replace 'admins' with your actual admin table name
            // Replace 'email_column' and 'password_column' with your actual column names
        $sql = "SELECT admin_id, email, password_hash FROM admins WHERE email = ?";            
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $adminEmail); // 's' for string (email)
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $admin = $result->fetch_assoc();
                    // 2. Verify the password (using password_verify for hashed passwords)
                    if (password_verify($adminPassword, $admin['password_hash'])) {
                        // Authentication successful
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_id'] = $admin['admin_id'];
                        $_SESSION['admin_email'] = $admin['email'];
                        
                        // Redirect to the admin dashboard
                        header("Location: index.php"); // Assuming index.php is your admin dashboard
                        exit(); // Always exit after a header redirect
                    } else {
                        // Password does not match
                        $_SESSION['flash_message'] = "Invalid email or password. Please try again.";
                        $_SESSION['flash_message_type'] = "danger";
                        // Note: A refresh might clear the alert if not handled by JS after redirect,
                        // using session for flash messages is better practice.
                    }
                } else {
                    // No admin found with that email
                    $_SESSION['flash_message'] = "Invalid email or password. Please try again.";
                    $_SESSION['flash_message_type'] = "danger";
                }
            } else {
                // Statement preparation failed
                error_log("Failed to prepare statement for admin login: " . $conn->error);
                $_SESSION['flash_message'] = "An error occurred during login. Please try again later.";
                $_SESSION['flash_message_type'] = "danger";
            }
        } else {
            // Database connection is not established or $conn is not a valid object
            $_SESSION['flash_message'] = "Database connection error. Please try again later.";
            $_SESSION['flash_message_type'] = "danger";
            error_log("Database connection variable \$conn is not set or not a mysqli object in adminlogin.php.");
        }

    } catch (Throwable $e) {
        // Catch any exceptions during the database operation
        error_log("Login error: " . $e->getMessage());
        $_SESSION['flash_message'] = "An unexpected error occurred. Please try again later.";
        $_SESSION['flash_message_type'] = "danger";
    } finally {
        // Close the statement
        if ($stmt) {
            $stmt->close();
        }
        // If your db_connect.php doesn't automatically close the connection at script end,
        // you might close it here: $conn->close();
    }
    // --- DATABASE INTEGRATION ENDS HERE ---
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced Admin Login</title>
    <style>
        /* Your CSS styles go here */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #8B7D6B 0%, #6B5B4F 100%); /* Interchanged colors */
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
            background: rgba(245, 245, 220, 0.5); /* Retaining a light khaki/beige */
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
            border-color: #6B5B4F; /* Using #6B5B4F */
            box-shadow: 0 0 0 3px rgba(107, 91, 79, 0.2); /* Based on #6B5B4F */
        }

        .form-group input::placeholder {
            color: #aaa;
        }

        .sign-in-btn {
            width: 100%;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%); /* Interchanged colors */
            color: white;
            box-shadow: 0 4px 15px rgba(107, 91, 79, 0.3); /* Based on #6B5B4F */
        }

        .sign-in-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(107, 91, 79, 0.4); /* Based on #6B5B4F */
        }

        .back-to-home-link {
            text-align: center;
            margin-top: 15px;
        }

        .back-to-home-link a {
            color: #6B5B4F; /* Using #6B5B4F */
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s;
        }

        .back-to-home-link a:hover {
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
        }

        .flash-message.success {
            background-color: #198754;
            border-color: #157347;
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

            .sign-in-btn {
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
            <p>Secure visitor management system for modern buildings. Empowering efficient administration and oversight.</p>
            
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
                    <h2>Admin Sign In</h2>
                </div>

                <?php
                // Display flash messages here if set
                if (isset($_SESSION['flash_message'])) {
                    $message = e($_SESSION['flash_message']);
                    $type = e($_SESSION['flash_message_type'] ?? 'info');
                    echo "<div class='flash-message {$type}'>{$message}</div>";
                    unset($_SESSION['flash_message']);
                    unset($_SESSION['flash_message_type']);
                }
                ?>

                <form id="adminLoginForm" method="POST" action="adminlogin.php">
                    <div class="form-group">
                        <label for="adminEmail">Admin Email:</label>
                        <input type="email" id="adminEmail" name="adminEmail" placeholder="Enter admin email" required>
                    </div>

                    <div class="form-group">
                        <label for="adminPassword">Password:</label>
                        <input type="password" id="adminPassword" name="adminPassword" placeholder="Enter admin password" required>
                    </div>

                    <button type="submit" class="sign-in-btn">Sign In as Admin</button>
                </form>

                <div class="back-to-home-link">
                    <a href="login.php">← Back to Main Login</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('adminLoginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('adminEmail').value;
            const password = document.getElementById('adminPassword').value;
            
            if (!email || !password) {
                alert('Please enter both admin email and password.');
                e.preventDefault(); 
            }
        });
    </script>
</body>
</html>