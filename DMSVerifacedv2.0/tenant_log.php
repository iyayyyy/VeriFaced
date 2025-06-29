<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php'; // Include your database connection file

function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Basic authentication check: Redirect if the admin is not logged in.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in to view activity logs.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: adminlogin.php"); // Redirect to your admin login page.
    exit();
}

$tenant_id = null;
$tenant_name = 'N/A';
$activity_logs = []; // Array to store fetched logs

// Get tenant_id from URL query string
if (isset($_GET['tenant_id']) && is_numeric($_GET['tenant_id'])) {
    $tenant_id = (int)$_GET['tenant_id'];

    if (isset($conn) && $conn instanceof mysqli) {
        // Fetch tenant name for display
        $stmt_tenant_name = $conn->prepare("SELECT first_name, last_name FROM tenants WHERE tenant_id = ? LIMIT 1");
        if ($stmt_tenant_name) {
            $stmt_tenant_name->bind_param("i", $tenant_id);
            $stmt_tenant_name->execute();
            $result_tenant_name = $stmt_tenant_name->get_result();
            if ($result_tenant_name->num_rows === 1) {
                $tenant_data = $result_tenant_name->fetch_assoc();
                $tenant_name = e($tenant_data['first_name'] . ' ' . $tenant_data['last_name']);
            }
            $stmt_tenant_name->close();
        }

        // Check if the request is for AJAX data fetch
        if (isset($_GET['fetch_data']) && $_GET['fetch_data'] === 'true') {
            header('Content-Type: application/json');
            $response = ['success' => false, 'logs' => [], 'message' => 'Failed to fetch logs.'];

            // MODIFIED: Select 'description' column for AJAX fetch
            $stmt_logs = $conn->prepare("SELECT action_type, description, timestamp FROM tenant_activity_log WHERE tenant_id = ? ORDER BY timestamp DESC");
            if ($stmt_logs) {
                $stmt_logs->bind_param("i", $tenant_id);
                $stmt_logs->execute();
                $result_logs = $stmt_logs->get_result();

                while ($row = $result_logs->fetch_assoc()) {
                    // Format timestamp for better readability
                    $formatted_timestamp = (new DateTime($row['timestamp']))->format('M d, Y h:i A');
                    $response['logs'][] = [
                        'action_type' => e(ucwords(str_replace('_', ' ', $row['action_type']))), // Capitalize and replace underscores
                        'description' => e($row['description']), // Include the description
                        'timestamp' => $formatted_timestamp
                    ];
                }
                $response['success'] = true;
                $response['message'] = "Logs fetched successfully.";
                $stmt_logs->close();
            } else {
                $response['message'] = "Database error: Could not prepare log statement.";
                error_log("Failed to prepare log statement for tenant_log: " . $conn->error);
            }
            echo json_encode($response);
            exit(); // Exit here if it's an AJAX request
        }

        // If it's a direct page load, fetch logs to display
        // MODIFIED: Select 'description' column for direct page load
        $stmt_logs = $conn->prepare("SELECT action_type, description, timestamp FROM tenant_activity_log WHERE tenant_id = ? ORDER BY timestamp DESC");
        if ($stmt_logs) {
            $stmt_logs->bind_param("i", $tenant_id);
            $stmt_logs->execute();
            $result_logs = $stmt_logs->get_result();

            while ($row = $result_logs->fetch_assoc()) {
                $activity_logs[] = [
                    'action_type' => e(ucwords(str_replace('_', ' ', $row['action_type']))),
                    'description' => e($row['description']), // Include the description
                    'timestamp' => (new DateTime($row['timestamp']))->format('M d, Y h:i A')
                ];
            }
            $stmt_logs->close();
        } else {
            error_log("Failed to prepare log statement for tenant_log (direct load): " . $conn->error);
        }

    } else {
        $_SESSION['flash_message'] = "Database connection error. Unable to fetch tenant logs.";
        $_SESSION['flash_message_type'] = "danger";
        error_log("Database connection not available in tenant_log.php.");
        header("Location: index.php");
        exit();
    }
} else {
    $_SESSION['flash_message'] = "No tenant selected to view logs.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: index.php"); // Redirect if no tenant_id is provided
    exit();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced - Activity Log for <?php echo $tenant_name; ?></title>
    <style>
        /* --- GLOBAL STYLES (Copied from tenant_info.php for consistency) --- */
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #F5F5DC 0%, #F0F0E8 100%); /* Subtle khaki gradient */
            color: #2C2C2C; /* Rich dark text */
            display: flex;
            font-size: 16px;
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .container {
            display: grid;
            grid-template-columns: 280px 1fr;
            width: 100vw;
            min-height: 100vh;
        }

        .sidebar {
            grid-column: 1 / 2;
            width: 280px;
            background: linear-gradient(180deg, #8B7D6B 0%, #6B5B4F 100%);
            color: #FFFFFF;
            padding: 30px 25px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            box-sizing: border-box;
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
            position: relative;
            align-items: center;
            transition: transform 0.3s ease-in-out;
            z-index: 1000;
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
            width: 100%;
        }

        .sidebar nav ul li {
            margin-bottom: 8px;
            width: 100%;
        }

        .sidebar nav ul li a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 1em;
            font-weight: 500;
            display: flex;
            align-items: center;
            padding: 14px 25px;
            width: 100%;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
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
            transform: translateX(0);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .logout-btn {
            margin-top: auto;
            background: linear-gradient(135deg, #D2691E 0%, #B8860B 100%);
            color: #FFFFFF;
            border: 2px solid rgba(255,255,255,0.2);
            padding: 12px 25px;
            width: 100%;
            max-width: none;
            cursor: pointer;
            border-radius: 12px;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: block;
            text-align: center;
            margin-left: 0;
            margin-right: 0;
            position: relative;
            z-index: 1;
            overflow: hidden;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
            box-sizing: border-box;
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

        /* Log Specific Styles for this page */
        .log-table-container {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            border-radius: 16px;
            padding: 25px;
            margin-top: 25px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12), 0 4px 10px rgba(107, 91, 79, 0.06);
            overflow-x: auto; /* Enable horizontal scrolling for small screens */
        }

        .log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
            min-width: 400px; /* Ensure minimum width for scroll */
        }

        .log-table th, .log-table td {
            padding: 14px 18px;
            text-align: left;
            border-bottom: 1px solid #E6DCC6;
        }

        .log-table thead th {
            background-color: #F0E68C; /* Light khaki header */
            color: #6B5B4F;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky; /* Keep header visible on scroll */
            top: 0;
            z-index: 2; /* Ensure header is above content */
        }

        .log-table tbody tr:nth-child(even) {
            background-color: #F8F8F0; /* Slightly different background for even rows */
        }

        .log-table tbody tr:hover {
            background-color: #F0F5E0; /* Light hover effect */
        }

        /* Generic styles for action types to ensure consistent text styling */
        .log-table td.action-type {
            font-weight: 600;
            /* Default color, overridden by more specific classes below */
            color: #495057; /* A neutral dark gray */
        }

        /* Specific colors for known action types */
        .log-table td.action-type.tenant_check-in,
        .log-table td.action-type.member_check-in {
            color: #28A745; /* Green for check-in */
        }

        .log-table td.action-type.tenant_check-out,
        .log-table td.action-type.member_check-out {
            color: #17A2B8; /* Blue for check-out */
        }

        /* Adjustments for longer action type strings */
        .log-table td.action-type {
            white-space: normal; /* Allow text to wrap naturally */
            word-break: break-word; /* Break long words if necessary */
        }

        .log-table .no-logs-message {
            text-align: center;
            padding: 20px;
            color: #6B5B4F;
            font-style: italic;
        }

        /* Flash Messages (copied for self-containment) */
        .flash-message {
            padding: 16px 24px;
            border-radius: 8px;
            margin: 0 0 24px 0;
            font-weight: 500;
            font-size: 0.95rem;
            line-height: 1.5;
            opacity: 1;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid;
            box-sizing: border-box;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08), 0 1px 4px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
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
                font-size: 1rem;
            }

            .tenant-info-card {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .tenant-info-card .profile-pic-large {
                margin-bottom: 20px;
                margin-right: 0;
            }

            .tenant-info-card .info {
                margin-right: 0;
                width: 100%;
            }

            .tenant-actions {
                flex-direction: column;
                width: 100%;
                margin-top: 20px;
            }

            .tenant-actions button {
                width: 100%;
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

            .tabs {
                flex-wrap: wrap; /* Allow tabs to wrap on very small screens */
                padding: 0 5px;
            }

            .tab-button {
                padding: 12px 15px;
                font-size: 0.95rem;
                margin-right: 8px;
                flex-grow: 1; /* Allow tabs to grow to fill space */
            }

            .log-table th, .log-table td {
                padding: 8px 10px;
                font-size: 0.8rem;
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
            .flash-message .btn-close {
                transition: none;
            }

            .flash-message:hover {
                transform: none;
            }

            .logout-modal-overlay,
            .logout-modal-content {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">Verifaced</div>
            <nav>
                <ul>
                    <li><a href="index.php">Unit Profiles</a></li>
                    <li><a href="registration.php">Account Registration</a></li>
                </ul>
            </nav>
            <a href="logout.php" class="logout-btn">Log out</a>
        </aside>
        <main class="main-content">
            <div id="flashMessageContainer">
                <?php
                if ($flash_message) {
                    echo "<div class='flash-message {$flash_message_type}'>{$flash_message}</div>";
                }
                ?>
            </div>

            <h2>Activity Log for <?php echo $tenant_name; ?></h2>

            <div class="log-table-container">
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Action Type</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($activity_logs)): ?>
                            <?php foreach ($activity_logs as $log):
                                // Prepare a simplified class name for styling based on action type
                                // This extracts the first word and converts to lowercase, e.g., "Tenant Check-in" -> "tenant_check-in"
                                // or "Member Check-in" -> "member_check-in"
                                $action_class = strtolower(str_replace(' ', '_', explode(':', $log['action_type'])[0]));
                            ?>
                                <tr>
                                    <td class="action-type <?php echo $action_class; ?>">
                                        <?php echo $log['action_type']; ?>
                                    </td>
                                    <td><?php echo $log['timestamp']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="2" class="no-logs-message">No activity logs found for this tenant.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="text-align: center; margin-top: 30px;">
                <button class="btn btn-primary" onclick="window.location.href='tenant_info.php?unit_id=<?php echo $tenant_id; ?>'">Back to Tenant Info</button>
            </div>
        </main>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar highlighting based on current page
            const currentPage = window.location.pathname.split("/").pop();
            document.querySelectorAll('.sidebar nav li a').forEach(link => {
                const linkPage = link.getAttribute('href');
                if (linkPage === currentPage) {
                    link.closest('li').classList.add('active');
                } else {
                    link.closest('li').classList.remove('active');
                }
            });

            // Flash message auto-hide (copied for self-containment)
            const flashMessage = document.querySelector('.flash-message');
            if (flashMessage) {
                setTimeout(() => {
                    flashMessage.classList.add('flash-exit');
                    flashMessage.addEventListener('transitionend', () => flashMessage.remove());
                }, 3000);
            }
        });
    </script>
</body>
</html>