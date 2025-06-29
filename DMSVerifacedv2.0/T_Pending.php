<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php'; // UNCOMMENTED

function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in to view pending visitor requests.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: login.php"); // Redirect to tenant login page
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

// Initialize variables for dynamic content
$pending_visitors_html = '';
$pending_badge_count = 0; // Will be updated dynamically

// Fetch Pending Visitors
if (isset($conn) && $conn instanceof mysqli) {
    $stmt = null;
    try {
        // MODIFIED SQL QUERY:
        // - Joins 'visitors' with 'tenants' (aliased as 't') to get the host's first_name and last_name.
        // - Joins 'visitors' with 'units' (aliased as 'u') to get the unit_number string.
        // - Selects v.relationship_to_tenant directly without any casting, as it's a VARCHAR.
        $sql = "SELECT v.visitor_id, v.full_name, u.unit_number, v.phone_number, v.purpose_of_visit, 
                       t.first_name AS host_first_name, t.last_name AS host_last_name, 
                       v.relationship_to_tenant, v.profile_pic_path, v.visit_timestamp
                FROM visitors v
                JOIN tenants t ON v.host_tenant_id = t.tenant_id
                JOIN units u ON v.unit_id = u.unit_id
                WHERE v.host_tenant_id = ? AND v.status = 'pending' 
                ORDER BY v.visit_timestamp ASC";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $logged_in_tenant_id);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                while ($visitor = $result->fetch_assoc()) {
                    // Correct image path or fallback to placeholder
                    $visitor_photo_src = !empty($visitor['profile_pic_path']) && file_exists($visitor['profile_pic_path']) ? e($visitor['profile_pic_path']) : 'https://placehold.co/80x80/A0A0A0/FFFFFF?text=No+Photo';
                    
                    $submission_time = new DateTime($visitor['visit_timestamp']);
                    $formatted_submission_time = e($submission_time->format('M d, Y h:i A'));
                    
                    // Display host's full name, fetched from the joined 'tenants' table
                    $host_full_name = e($visitor['host_first_name'] . ' ' . $visitor['host_last_name']);

                    $pending_visitors_html .= "
                        <div class='visitor-card' data-visitor-id='" . e($visitor['visitor_id']) . "'>
                            <div class='visitor-info'>
                                <div class='image-placeholder'>
                                    <img src='{$visitor_photo_src}' alt='Visitor ID Photo' onerror=\"this.onerror=null;this.src='https://placehold.co/80x80/A0A0A0/FFFFFF?text=No+Photo';\">
                                </div>
                                <div class='visitor-details'>
                                    <p><strong>Name:</strong> <span class='detail-value visitor-name'>" . e($visitor['full_name']) . "</span></p>
                                    <p><strong>Unit Number:</strong> <span class='detail-value unit-number'>" . e($visitor['unit_number']) . "</span></p>
                                    <p><strong>Phone:</strong> <span class='detail-value phone-number'>" . e($visitor['phone_number']) . "</span></p>
                                    <p><strong>Person to Visit:</strong> <span class='detail-value person-to-visit'>" . $host_full_name . "</span></p>
                                    <p><strong>Relationship:</strong> <span class='detail-value relationship-to-tenant'>" . e($visitor['relationship_to_tenant']) . "</span></p>
                                    <p><strong>Purpose:</strong> <span class='detail-value purpose-of_visit'>" . e($visitor['purpose_of_visit']) . "</span></p>
                                    <p><strong>Submitted:</strong> <span class='detail-value submission-time'>" . $formatted_submission_time . "</span></p>
                                </div>
                            </div>
                            <div class='action-buttons'>
                                <button class='accept' onclick=\"showModal('acceptModal', '" . e($visitor['visitor_id']) . "')\"><span class='circle-icon'>✓</span>Accept</button>
                                <button class='reject' onclick=\"showModal('rejectModal', '" . e($visitor['visitor_id']) . "')\"><span class='circle-icon'>✕</span>Reject</button>
                            </div>
                        </div>
                    ";
                }
            } else {
                $pending_visitors_html = "
                    <div class='empty-state'>
                        <h3>No Pending Requests</h3>
                        <p>All visitor requests have been processed. New requests will appear here.</p>
                    </div>
                ";
            }
        } else {
            $pending_visitors_html = "<div class='empty-state'><h3>Error loading pending requests.</h3><p>Database statement preparation failed.</p></div>";
            error_log("Failed to prepare statement for T_Pending: " . $conn->error);
        }
    } catch (Throwable $e) {
        $pending_visitors_html = "<div class='empty-state'><h3>An unexpected error occurred.</h3><p>Please try again later.</p></div>";
        error_log("Error fetching pending visitors for T_Pending: " . $e->getMessage());
    } finally {
        if ($stmt) {
            $stmt->close();
        }
    }

    // Fetch Pending count for badge (from actual database)
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
        error_log("Error fetching pending count for T_Pending: " . $e->getMessage());
    } finally {
        if ($stmt_pending_count) {
            $stmt_pending_count->close();
        }
    }
} else {
    $pending_visitors_html = "<div class='empty-state'><h3>Database connection not available.</h3><p>Please check configuration.</p></div>";
    error_log("Database connection not available in T_Pending.php.");
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
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verifaced: Pending Requests</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* Compact Professional Khaki Theme CSS */
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
      --action-reject: #8B7355;
      --action-reject-dark: #6B5B47;
      
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
      background: linear-gradient(135deg, rgba(139, 115, 85, 0.1) 0%, rgba(139, 115, 85, 0.05) 100%);
      color: var(--action-reject);
      text-decoration: none;
      border-radius: var(--radius-md);
      text-align: center;
      font-weight: 600;
      font-size: 13px;
      transition: var(--transition-normal);
      border: 1px solid rgba(139, 115, 85, 0.2);
    }

    .logout:hover {
      background: var(--action-reject);
      color: var(--white);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
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

    /* COMPACT VISITOR CARDS */
    .visitor-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 20px;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--gray-200);
      transition: var(--transition-normal);
      position: relative;
      overflow: hidden;
      margin-bottom: 16px;
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .visitor-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }

    .visitor-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .visitor-info {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      flex-grow: 1;
      min-width: 320px;
    }

    .image-placeholder {
      width: 80px;
      height: 80px;
      background: linear-gradient(135deg, var(--khaki-light) 0%, var(--khaki-muted) 100%);
      border: 2px solid var(--white);
      border-radius: var(--radius-md);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--gray-500);
      font-size: 11px;
      font-weight: 600;
      text-align: center;
      flex-shrink: 0;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
      transition: var(--transition-normal);
    }

    .image-placeholder:hover {
      transform: scale(1.02);
      box-shadow: var(--shadow-md);
    }

    .image-placeholder img {
      width: 100%;
      height: 100%;
      border-radius: calc(var(--radius-md) - 2px);
      object-fit: cover;
    }

    .visitor-details {
      flex-grow: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .visitor-details p {
      margin-bottom: 0;
      font-size: 13px;
      color: var(--gray-800);
      display: grid;
      grid-template-columns: 120px 1fr;
      align-items: center;
      gap: 12px;
      line-height: 1.4;
      margin-bottom: 6px;
    }

    .visitor-details strong {
      color: var(--khaki-dark);
      font-weight: 600;
      text-align: left;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      justify-self: start;
    }

    .visitor-details span.detail-value {
      color: var(--gray-700);
      font-weight: 500;
      font-style: normal;
      background: var(--white);
      padding: 6px 10px;
      border-radius: var(--radius-sm);
      border: none;
      word-break: break-word;
      font-size: 12px;
      justify-self: start;
      width: 100%;
      max-width: 280px;
    }

    .visitor-details p:last-child {
      margin-bottom: 0;
    }

    .action-buttons {
      display: flex;
      flex-direction: column;
      gap: 8px;
      min-width: 120px;
      flex-shrink: 0;
    }

    .action-buttons button {
      padding: 10px 16px;
      border: none;
      border-radius: var(--radius-md);
      font-weight: 600;
      font-size: 12px;
      cursor: pointer;
      transition: var(--transition-normal);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      box-shadow: var(--shadow-sm);
      font-family: inherit;
    }

    .action-buttons button:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .action-buttons button.accept {
      background: linear-gradient(135deg, var(--action-accept) 0%, var(--action-accept-dark) 100%);
      color: var(--white);
    }

    .action-buttons button.accept:hover {
      background: linear-gradient(135deg, var(--action-accept-dark) 0%, #6B6B23 100%);
    }

    .action-buttons button.reject {
      background: linear-gradient(135deg, var(--action-reject) 0%, var(--action-reject-dark) 100%);
      color: var(--white);
    }

    .action-buttons button.reject:hover {
      background: linear-gradient(135deg, var(--action-reject-dark) 0%, #5A4A39 100%);
    }
    
    .circle-icon {
      font-size: 12px;
      font-weight: 700;
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

    .btn-accept-modal {
      background: linear-gradient(135deg, var(--action-accept) 0%, var(--action-accept-dark) 100%);
      color: var(--white);
    }

    .btn-accept-modal:hover {
      background: linear-gradient(135deg, var(--action-accept-dark) 0%, #6B6B23 100%);
    }

    .btn-reject-modal {
      background: linear-gradient(135deg, var(--action-reject) 0%, var(--action-reject-dark) 100%);
      color: var(--white);
    }

    .btn-reject-modal:hover {
      background: linear-gradient(135deg, var(--action-reject-dark) 0%, #5A4A39 100%);
    }

    .btn-confirm-logout {
      background: var(--action-reject);
      color: var(--white);
    }

    .btn-confirm-logout:hover {
      background: var(--action-reject-dark);
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

    .app-message.error {
      background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
      color: var(--danger);
      border-color: rgba(220, 53, 69, 0.2);
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

    /* COMPACT EMPTY STATE */
    .empty-state {
      text-align: center;
      padding: 48px 20px;
      color: var(--gray-600);
      background: linear-gradient(135deg, 
        rgba(255, 255, 255, 0.8) 0%, 
        rgba(245, 245, 220, 0.6) 100%);
      border-radius: var(--radius-lg);
      margin-top: 16px;
      border: 2px dashed var(--gray-300);
    }

    .empty-state h3 {
      font-size: 20px;
      margin-bottom: 12px;
      color: var(--gray-700);
      font-weight: 600;
    }

    .empty-state p {
      font-size: 14px;
      opacity: 0.8;
      line-height: 1.5;
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
      
      .visitor-info {
        min-width: 280px;
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

      .visitor-card {
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
        padding: 16px;
      }

      .visitor-info {
        flex-direction: column;
        text-align: center;
        align-items: center; /* Center image placeholder */
        min-width: auto;
      }

      .visitor-details {
        text-align: left;
      }

      .visitor-details p {
        grid-template-columns: 1fr;
        gap: 4px;
        text-align: left;
      }

      .visitor-details strong {
        justify-self: start;
      }

      .visitor-details span.detail-value {
        justify-self: start;
        max-width: 100%;
      }

      .action-buttons {
        flex-direction: row;
        min-width: auto;
        width: 100%;
      }

      .action-buttons button {
        flex: 1;
      }

      .modal-content {
        margin: 12px;
        padding: 20px;
      }
    }

    @media (max-width: 480px) {
      .main-content {
        padding: 12px;
      }
      
      .visitor-card {
        padding: 16px;
      }
      
      .image-placeholder {
        width: 64px;
        height: 64px;
      }
      
      .content-header h1 {
        font-size: 22px;
      }

      .action-buttons {
        flex-direction: column;
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

    .visitor-card {
      animation: fadeInUp 0.4s ease-out;
    }

    .visitor-card:nth-child(even) {
      animation-delay: 0.05s;
    }

    @keyframes fadeInUpCard {
      from {
        opacity: 0;
        transform: translateY(15px) scale(0.98);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .visitor-card.new-card-animation {
      animation: fadeInUpCard 0.4s ease-out forwards;
    }

    /* FOCUS STYLES FOR ACCESSIBILITY */
    .action-buttons button:focus,
    .modal-buttons button:focus,
    .sidebar nav li a:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES */
    .action-buttons button:disabled,
    .modal-buttons button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* PRINT STYLES */
    @media print {
      .sidebar,
      .action-buttons,
      .modal {
        display: none;
      }
      
      .main-content {
        padding: 0;
      }
      
      .visitor-card {
        box-shadow: none;
        border: 1px solid var(--gray-300);
        break-inside: avoid;
      }
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
          <li class="active"><a href="T_Pending.php"><span></span> Pending <span class="badge" id="pendingBadge"><?php echo $pending_badge_count; ?></span></a></li>
          <li><a href="T_MemberRegistration.php"><span></span> Member Registration</a></li>
        </ul>
      </nav>
      <a href="#" class="logout" id="logoutLink">Log out</a>
    </aside>

    <main class="main-content">
      <div class="content-header">
        <h1>Pending Requests</h1>
        <p>Review and manage visitor requests awaiting your approval</p>
      </div>
      
      <div id="appMessageContainer">
        <?php if ($flash_message): // Display flash message from PHP ?>
            <div class='app-message <?php echo $flash_message_type; ?> show'><?php echo $flash_message; ?></div>
        <?php endif; ?>
      </div> 
      <div id="visitorContainer">
        <?php echo $pending_visitors_html; // Display pending visitors ?>
      </div>
    </main>
  </div>

  <div id="acceptModal" class="modal">
    <div class="modal-content">
      <h3>Confirm Accept</h3>
      <p>Are you sure you want to accept this visitor request?</p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="cancelAccept">Cancel</button>
        <button class="btn-accept-modal" id="confirmAccept">Accept</button>
      </div>
    </div>
  </div>

  <div id="rejectModal" class="modal">
    <div class="modal-content">
      <h3>Confirm Reject</h3>
      <p>Are you sure you want to reject this visitor request?</p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="cancelReject">Cancel</button>
        <button class="btn-reject-modal" id="confirmReject">Reject</button>
      </div>
    </div>
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
    const visitorContainer = document.getElementById('visitorContainer');
    const acceptModal = document.getElementById('acceptModal');
    const rejectModal = document.getElementById('rejectModal');
    const confirmAcceptBtn = document.getElementById('confirmAccept');
    const cancelAcceptBtn = document.getElementById('cancelAccept');
    const confirmRejectBtn = document.getElementById('confirmReject');
    const cancelRejectBtn = document.getElementById('cancelReject');
    const logoutLink = document.getElementById('logoutLink');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogoutBtn = document.getElementById('confirmLogout');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    const pendingBadge = document.getElementById('pendingBadge');
    const appMessageContainer = document.getElementById('appMessageContainer');

    let visitorToProcessId = null; // To store the ID of the visitor being processed

    // Function to display app-level messages
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

    function updatePendingBadgeCount(newCount) { // Pass the new count from PHP/AJAX
        if (pendingBadge) {
            pendingBadge.textContent = newCount;
        }
    }

    function showModal(modalId, visitorId) {
        visitorToProcessId = visitorId;
        document.getElementById(modalId).classList.add('show');
    }

    function processVisitorAction(visitorId, action) {
        fetch('process_visitor_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${action}&visitor_id=${visitorId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showAppFeedback(data.message, 'success');
                // Remove the processed card from the DOM
                const cardToRemove = document.querySelector(`.visitor-card[data-visitor-id='${visitorId}']`);
                if (cardToRemove) {
                    cardToRemove.style.transition = 'all 0.25s ease';
                    cardToRemove.style.transform = 'scale(0.95)';
                    cardToRemove.style.opacity = '0';
                    setTimeout(() => {
                        cardToRemove.remove();
                        if (data.new_pending_count !== undefined) {
                            updatePendingBadgeCount(data.new_pending_count);
                        }
                        
                        // If no more visitors, show empty state
                        if (visitorContainer.children.length === 0) {
                            visitorContainer.innerHTML = `
                                <div class="empty-state">
                                    <h3>No Pending Requests</h3>
                                    <p>All visitor requests have been processed. New requests will appear here.</p>
                                </div>
                            `;
                        }
                    }, 250);
                }
            } else {
                showAppFeedback(data.message, 'error');
            }
        })
        .catch(error => {
            console.error(`Error processing ${action} action:`, error);
            showAppFeedback('An error occurred while processing the request.', 'error');
        });
    }

    confirmAcceptBtn.addEventListener('click', () => {
        if (visitorToProcessId) {
            processVisitorAction(visitorToProcessId, 'accept');
        }
        acceptModal.classList.remove('show');
        visitorToProcessId = null;
    });

    cancelAcceptBtn.addEventListener('click', () => {
        acceptModal.classList.remove('show');
        visitorToProcessId = null;
    });

    confirmRejectBtn.addEventListener('click', () => {
        if (visitorToProcessId) {
            processVisitorAction(visitorToProcessId, 'reject');
        }
        rejectModal.classList.remove('show');
        visitorToProcessId = null;
    });

    cancelRejectBtn.addEventListener('click', () => {
        rejectModal.classList.remove('show');
        visitorToProcessId = null;
    });

    // Logout Logic
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
    [acceptModal, rejectModal, logoutModal].forEach(modal => {
        if (modal) { // Check if modal exists
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('show');
                    visitorToProcessId = null; // Reset if a visitor was being processed
                }
            });
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

        // Initial page animation for visitor cards
        const visitorCards = document.querySelectorAll('.visitor-card');
        visitorCards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            setTimeout(() => {
                card.style.transition = 'all 0.4s ease';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }, 50 + (index * 50));
        });
    });
  </script>
</body>
</html>