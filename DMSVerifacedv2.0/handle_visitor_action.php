<?php
session_start(); // Start session to use flash messages
require_once 'db_connect.php'; // Ensure your database connection is included

function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

$message = "Invalid or expired link.";
$type = "error"; // Default type for errors or generic messages

if (isset($conn) && $conn instanceof mysqli) {
    if (isset($_GET['token']) && isset($_GET['action'])) {
        $token = $_GET['token'];
        $action = $_GET['action']; // 'accept' or 'reject'

        // Validate action
        if ($action !== 'accept' && $action !== 'reject') {
            $message = "Invalid action specified.";
            $_SESSION['flash_message'] = $message;
            $_SESSION['flash_message_type'] = "danger";
            header("Location: T_VisitorsList.php"); // Redirect even for invalid action
            exit();
        } else {
            // Find visitor by token, ensure token is not used and not expired
            $stmt = null;
            try {
                $sql = "SELECT visitor_id, full_name, status, token_expires_at, token_used FROM visitors WHERE action_token = ? LIMIT 1";
                $stmt = $conn->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("s", $token);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $visitor_data = $result->fetch_assoc();
                    $stmt->close();

                    if ($visitor_data) {
                        $visitor_id = $visitor_data['visitor_id'];
                        $visitor_name = $visitor_data['full_name']; // Get visitor name for message
                        $current_status = $visitor_data['status'];
                        $token_expires_at = new DateTime($visitor_data['token_expires_at']);
                        $token_used = $visitor_data['token_used'];
                        $now = new DateTime();

                        if ($token_used) {
                            $message = "This link has already been used for visitor '{$visitor_name}'.";
                            $type = "warning";
                        } elseif ($now > $token_expires_at) {
                            $message = "This link has expired for visitor '{$visitor_name}'.";
                            $type = "danger";
                        } elseif ($current_status !== 'pending') {
                            $message = "Visitor request for '{$visitor_name}' already " . e($current_status) . ".";
                            $type = "info"; // Use info for already processed
                        } else {
                            // Token is valid, process action
                            $new_status = ($action === 'accept' ? 'accepted' : 'rejected');
                            $update_sql = "UPDATE visitors SET status = ?, processed_at = NOW(), token_used = TRUE WHERE visitor_id = ? AND action_token = ?";
                            $stmt_update = $conn->prepare($update_sql);

                            if ($stmt_update) {
                                $stmt_update->bind_param("sis", $new_status, $visitor_id, $token);
                                if ($stmt_update->execute()) {
                                    $message = "Visitor request for '{$visitor_name}' successfully " . e($new_status) . ".";
                                    $type = "success";
                                    
                                    // Set flash message for redirection
                                    $_SESSION['flash_message'] = $message;
                                    $_SESSION['flash_message_type'] = $type;
                                    header("Location: T_VisitorsList.php"); // Always redirect to Visitors List
                                    exit();
                                } else {
                                    $message = "Error updating visitor status for '{$visitor_name}'.";
                                    $type = "danger";
                                    error_log("DB Error: " . $stmt_update->error);
                                }
                                $stmt_update->close();
                            } else {
                                $message = "Database error: Could not prepare update statement.";
                                $type = "danger";
                                error_log("DB Prepare Error: " . $conn->error);
                            }
                        }
                    } else {
                        $message = "Invalid token: Visitor not found.";
                        $type = "danger";
                    }
                } else {
                    $message = "Database error: Could not prepare select statement.";
                    $type = "danger";
                    error_log("DB Prepare Error: " . $conn->error);
                }
            } catch (Throwable $e) {
                $message = "An unexpected error occurred during visitor action.";
                $type = "danger";
                error_log("Visitor action error: " . $e->getMessage());
            } finally {
                if ($stmt) {
                    $stmt->close();
                }
            }
        }
    } else {
        $message = "Missing token or action parameters.";
        $type = "warning";
    }
} else {
    $message = "Database connection error. Unable to process request.";
    $type = "danger";
}

// If not redirected (e.g., if an error occurred before redirect), display this page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visitor Action Result</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #F5F5DC 0%, #E6E2C3 50%, #DDD3A0 100%); /* Khaki theme */
            color: #343A40;
            margin: 0;
            padding: 20px;
            text-align: center;
        }
        .container {
            background: #FFFFFF;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(139, 134, 78, 0.2);
            max-width: 500px;
            width: 100%;
            border: 1px solid #E9ECEF;
            position: relative;
            overflow: hidden;
        }
        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #BDB76B 0%, #8B864E 100%);
        }
        h1 {
            font-size: 28px;
            color: #495057;
            margin-bottom: 20px;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .message-box {
            padding: 15px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 20px;
        }
        .message-box.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28A745;
            border: 1px solid rgba(40, 167, 69, 0.2);
        }
        .message-box.error, .message-box.danger { /* Combined error and danger */
            background-color: rgba(220, 53, 69, 0.1);
            color: #DC3545;
            border: 1px solid rgba(220, 53, 69, 0.2);
        }
        .message-box.warning, .message-box.info { /* For warnings/info about token status */
            background-color: rgba(255, 193, 7, 0.1);
            color: #FFC107;
            border: 1px solid rgba(255, 193, 7, 0.2);
        }
        .back-link {
            display: inline-block;
            padding: 12px 25px;
            background: linear-gradient(135deg, #BDB76B 0%, #8B864E 100%);
            color: #FFFFFF;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(139, 134, 78, 0.15);
        }
        .back-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(139, 134, 78, 0.2);
            background: linear-gradient(135deg, #8B864E 0%, #6B6B23 100%);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Visitor Action Result</h1>
        <div class="message-box <?php echo $type; ?>">
            <?php echo $message; ?>
        </div>
        <a href="login.php" class="back-link">Go to Login</a>
    </div>
</body>
</html>
