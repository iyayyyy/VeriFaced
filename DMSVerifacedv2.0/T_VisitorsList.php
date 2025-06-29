<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Include your database connection file. This file MUST create a $conn variable
// that is a valid MySQLi database connection object.
require_once 'db_connect.php'; // UNCOMMENTED

function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in to view your visitor list.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: login.php"); // Redirect to tenant login page
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

// Initialize variables for dynamic content
$accepted_visitors_html = '';
$rejected_visitors_html = ''; // New variable for rejected visitors
$pending_badge_count = 0; // Will be updated dynamically

// Fetch Visitors
if (isset($conn) && $conn instanceof mysqli) {
    $stmt_accepted = null;
    $stmt_rejected = null;
    try {
        // --- Fetch Accepted Visitors ---
        // Exclude visitors who have been added as members (status 'member_added')
        $sql_accepted = "SELECT visitor_id, full_name, phone_number, purpose_of_visit, relationship_to_tenant, profile_pic_path, visit_timestamp
                        FROM visitors
                        WHERE host_tenant_id = ? AND status = 'accepted' AND status != 'member_added' ORDER BY visit_timestamp DESC";
        
        $stmt_accepted = $conn->prepare($sql_accepted);
        if ($stmt_accepted) {
            $stmt_accepted->bind_param("i", $logged_in_tenant_id);
            $stmt_accepted->execute();
            $result_accepted = $stmt_accepted->get_result();

            if ($result_accepted->num_rows > 0) {
                while ($visitor = $result_accepted->fetch_assoc()) {
                    $initials = '';
                    if (!empty($visitor['full_name'])) {
                        $name_parts = explode(' ', $visitor['full_name']);
                        foreach ($name_parts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    } else {
                        $initials = 'V'; // Default if name is empty
                    }
                    
                    $visitor_photo_src = !empty($visitor['profile_pic_path']) && file_exists($visitor['profile_pic_path']) ? e($visitor['profile_pic_path']) : 'https://placehold.co/80x80/A0A0A0/FFFFFF?text=' . $initials;
                    
                    $processed_timestamp = new DateTime($visitor['visit_timestamp']); 
                    $processed_on = e($processed_timestamp->format('M d, Y h:i A')); 

                    $accepted_visitors_html .= "
                        <div class='visitor-card accepted-card' data-visitor-id='" . e($visitor['visitor_id']) . "'>
                            <div class='visitor-header'>
                                <div class='visitor-avatar'><img src='{$visitor_photo_src}' alt='Visitor Photo' onerror=\"this.onerror=null;this.src='https://placehold.co/80x80/A0A0A0/FFFFFF?text=" . $initials . "';\"></div>
                                <div class='visitor-name'>
                                    <h3>" . e($visitor['full_name']) . "</h3>
                                    <div class='visitor-status accepted-status'>Approved</div>
                                </div>
                            </div>
                            <div class='visitor-details'>
                                <div class='detail-item'>
                                    <span class='detail-label'>Phone Number</span>
                                    <span class='detail-value'>" . e($visitor['phone_number']) . "</span>
                                </div>
                                <div class='detail-item'>
                                    <span class='detail-label'>Purpose</span>
                                    <span class='detail-value'>" . e($visitor['purpose_of_visit']) . "</span>
                                </div>
                                <div class='detail-item'>
                                    <span class='detail-label'>Relationship</span>
                                    <span class='detail-value'>" . e($visitor['relationship_to_tenant']) . "</span>
                                </div>
                                <div class='detail-item'>
                                    <span class='detail-label'>Processed On</span>
                                    <span class='detail-value'>" . $processed_on . "</span>
                                </div>
                            </div>
                            <div class='visitor-actions'>
                                <button class='btn btn-delete' onclick=\"showConfirmModal('Delete Visitor', 'Are you sure you want to delete " . e($visitor['full_name']) . "? This action cannot be undone.', () => deleteVisitor('" . e($visitor['visitor_id']) . "'))\">Delete Visitor</button>
                                <button class='btn btn-add' data-visitor-id='" . e($visitor['visitor_id']) . "' data-visitor-name='" . e($visitor['full_name']) . "' onclick=\"showConfirmModal('Add as Member', 'Are you sure you want to add " . e($visitor['full_name']) . " as a permanent member to your unit?', () => addAsMember('" . e($visitor['visitor_id']) . "', '" . e($visitor['full_name']) . "'), true)\">Add as Member</button>
                            </div>
                        </div>
                    ";
                }
            } else {
                $accepted_visitors_html = "
                    <div class='empty-state'>
                        <h3>No Accepted Visitors Yet</h3>
                        <p>Approved visitor requests will appear here.</p>
                    </div>
                ";
            }
        } else {
            error_log("Failed to prepare accepted visitors statement for T_VisitorsList: " . $conn->error);
            $accepted_visitors_html = "<div class='empty-state'><h3>Error loading accepted visitors.</h3><p>Database statement preparation failed.</p></div>";
        }
    } catch (Throwable $e) {
        error_log("Error fetching accepted visitors for T_VisitorsList: " . $e->getMessage());
        $accepted_visitors_html = "<div class='empty-state'><h3>An unexpected error occurred.</h3><p>Please try again later.</p></div>";
    } finally {
        if ($stmt_accepted) {
            $stmt_accepted->close();
        }
    }

    try {
        // --- Fetch Rejected Visitors ---
        $sql_rejected = "SELECT visitor_id, full_name, phone_number, purpose_of_visit, relationship_to_tenant, profile_pic_path, visit_timestamp
                        FROM visitors
                        WHERE host_tenant_id = ? AND status = 'rejected' ORDER BY visit_timestamp DESC";
        
        $stmt_rejected = $conn->prepare($sql_rejected);
        if ($stmt_rejected) {
            $stmt_rejected->bind_param("i", $logged_in_tenant_id);
            $stmt_rejected->execute();
            $result_rejected = $stmt_rejected->get_result();

            if ($result_rejected->num_rows > 0) {
                while ($visitor = $result_rejected->fetch_assoc()) {
                    $initials = '';
                    if (!empty($visitor['full_name'])) {
                        $name_parts = explode(' ', $visitor['full_name']);
                        foreach ($name_parts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    } else {
                        $initials = 'V';
                    }
                    
                    $visitor_photo_src = !empty($visitor['profile_pic_path']) && file_exists($visitor['profile_pic_path']) ? e($visitor['profile_pic_path']) : 'https://placehold.co/80x80/A0A0A0/FFFFFF?text=' . $initials;
                    
                    $processed_timestamp = new DateTime($visitor['visit_timestamp']); 
                    $processed_on = e($processed_timestamp->format('M d, Y h:i A')); 

                    $rejected_visitors_html .= "
                        <div class='visitor-card rejected-card' data-visitor-id='" . e($visitor['visitor_id']) . "'>
                            <div class='visitor-header'>
                                <div class='visitor-avatar'><img src='{$visitor_photo_src}' alt='Visitor Photo' onerror=\"this.onerror=null;this.src='https://placehold.co/80x80/A0A0A0/FFFFFF?text=" . $initials . "';\"></div>
                                <div class='visitor-name'>
                                    <h3>" . e($visitor['full_name']) . "</h3>
                                    <div class='visitor-status rejected-status'>Rejected</div>
                                </div>
                            </div>
                            <div class='visitor-details'>
                                <div class='detail-item'>
                                    <span class='detail-label'>Phone Number</span>
                                    <span class='detail-value'>" . e($visitor['phone_number']) . "</span>
                                </div>
                                <div class='detail-item'>
                                    <span class='detail-label'>Purpose</span>
                                    <span class='detail-value'>" . e($visitor['purpose_of_visit']) . "</span>
                                </div>
                                <div class='detail-item'>
                                    <span class='detail-label'>Relationship</span>
                                    <span class='detail-value'>" . e($visitor['relationship_to_tenant']) . "</span>
                                </div>
                                <div class='detail-item'>
                                    <span class='detail-label'>Processed On</span>
                                    <span class='detail-value'>" . $processed_on . "</span>
                                </div>
                            </div>
                            <div class='visitor-actions'>
                                <button class='btn btn-delete' onclick=\"showConfirmModal('Delete Visitor', 'Are you sure you want to delete " . e($visitor['full_name']) . "? This action cannot be undone.', () => deleteVisitor('" . e($visitor['visitor_id']) . "'))\">Delete Visitor</button>
                                </div>
                        </div>
                    ";
                }
            } else {
                $rejected_visitors_html = "
                    <div class='empty-state'>
                        <h3>No Rejected Visitors Yet</h3>
                        <p>Rejected visitor requests will appear here.</p>
                    </div>
                ";
            }
        } else {
            error_log("Failed to prepare rejected visitors statement for T_VisitorsList: " . $conn->error);
            $rejected_visitors_html = "<div class='empty-state'><h3>Error loading rejected visitors.</h3><p>Database statement preparation failed.</p></div>";
        }
    } catch (Throwable $e) {
        error_log("Error fetching rejected visitors for T_VisitorsList: " . $e->getMessage());
        $rejected_visitors_html = "<div class='empty-state'><h3>An unexpected error occurred.</h3><p>Please try again later.</p></div>";
    } finally {
        if ($stmt_rejected) {
            $stmt_rejected->close();
        }
    }

    // Fetch Pending count for badge (only count actual pending)
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
        error_log("Error fetching pending count for T_VisitorsList: " . $e->getMessage());
    } finally {
        if ($stmt_pending_count) {
            $stmt_pending_count->close();
        }
    }
} else {
    $accepted_visitors_html = "<div class='empty-state'><h3>Database connection not available.</h3><p>Please check configuration.</p></div>";
    $rejected_visitors_html = "<div class='empty-state'><h3>Database connection not available.</h3><p>Please check configuration.</p></div>";
    error_log("Database connection not available in T_VisitorsList.php.");
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
  <title>Verifaced: Visitor's List</title>
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
      --success: #52C41A; /* Adjusted for better contrast with light backgrounds */
      --danger: #DC3545; /* Retained original danger */
      --warning: #FFC107;
      --info: #17A2B8;
      
      /* Custom Action Colors */
      --action-delete: #8B7355; /* Adjusted to a warm brown/khaki */
      --action-delete-dark: #6B5B47;
      --action-delete-light: rgba(139, 115, 85, 0.1);
      --action-delete-border: rgba(139, 115, 85, 0.2);

      --status-rejected-bg: rgba(220, 53, 69, 0.1); /* Light red for rejected status */
      --status-rejected-text: #DC3545; /* Dark red for rejected text */
      --status-rejected-border: rgba(220, 53, 69, 0.2);
      
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
      border-radius: 8px; /* Matched T_Pending.php */
      padding: 2px 6px; /* Matched T_Pending.php */
      font-size: 10px; /* Matched T_Pending.php */
      font-weight: 600;
      margin-left: auto;
      min-width: 16px; /* Matched T_Pending.php */
      text-align: center;
      box-shadow: var(--shadow-sm);
    }

    .logout {
      position: absolute;
      bottom: 20px;
      left: 16px;
      right: 16px;
      padding: 12px;
      background: linear-gradient(135deg, var(--action-delete-light) 0%, rgba(139, 115, 85, 0.05) 100%);
      color: var(--action-delete);
      text-decoration: none;
      border-radius: var(--radius-md);
      text-align: center;
      font-weight: 600;
      font-size: 13px;
      transition: var(--transition-normal);
      border: 1px solid var(--action-delete-border);
    }

    .logout:hover {
      background: var(--action-delete);
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

    /* COMPACT VISITOR CARDS GRID */
    .visitors-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 16px;
      /* margin-bottom: 80px; Removed space for floating button */
    }

    .visitor-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 20px;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--gray-200);
      transition: var(--transition-normal);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }

    .visitor-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      /* Default to Khaki primary for accepted, red for rejected */
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%); 
    }

    .visitor-card.rejected-card::before {
        background: linear-gradient(90deg, var(--danger) 0%, var(--action-delete-dark) 100%);
    }

    .visitor-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .visitor-header {
      display: flex;
      align-items: center;
      gap: 16px;
      margin-bottom: 16px;
    }

    .visitor-avatar {
      width: 64px;
      height: 64px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--white);
      font-size: 22px;
      font-weight: 700;
      box-shadow: var(--shadow-sm);
      border: 2px solid var(--white);
      overflow: hidden;
      transition: var(--transition-normal);
      flex-shrink: 0;
    }

    .visitor-avatar:hover {
      transform: scale(1.05);
      box-shadow: var(--shadow-md);
    }

    .visitor-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .visitor-name {
      flex: 1;
      min-width: 0;
    }

    .visitor-name h3 {
      color: var(--gray-800);
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 6px;
      letter-spacing: -0.2px;
      word-break: break-word;
    }

    .visitor-status {
      background: linear-gradient(135deg, rgba(82, 196, 26, 0.1) 0%, rgba(82, 196, 26, 0.05) 100%);
      color: var(--success);
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      border: 1px solid rgba(82, 196, 26, 0.2);
      display: inline-block;
    }

    .visitor-status.rejected-status {
        background: var(--status-rejected-bg);
        color: var(--status-rejected-text);
        border-color: var(--status-rejected-border);
    }

    .visitor-details {
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin-bottom: 16px;
      flex-grow: 1;
    }

    .detail-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .detail-label {
      font-size: 10px;
      color: var(--gray-500);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .detail-value {
      color: var(--gray-700);
      font-weight: 500;
      font-size: 12px;
      background: var(--white);
      padding: 6px 8px;
      border-radius: var(--radius-sm);
      word-break: break-word;
      line-height: 1.3;
    }

    .visitor-actions {
      display: flex;
      gap: 8px;
      margin-top: auto;
    }

    .btn {
      padding: 8px 12px;
      border: none;
      border-radius: var(--radius-md);
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-normal);
      flex: 1;
      font-family: inherit;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 4px;
    }

    .btn-delete {
      background: linear-gradient(135deg, var(--action-delete-light) 0%, rgba(139, 115, 85, 0.05) 100%);
      color: var(--action-delete);
      border: 1px solid var(--action-delete-border);
    }

    .btn-delete:hover {
      background: var(--action-delete);
      color: var(--white);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn-add {
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      color: var(--white);
      box-shadow: var(--shadow-sm);
    }

    .btn-add:hover {
      background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }
    
    .btn-add:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        background: var(--gray-300);
        color: var(--gray-600);
        box-shadow: none;
        transform: none;
    }

    /* COMPACT FLOATING ADD BUTTON - REMOVED */
    /* .floating-add {
      position: fixed;
      bottom: 24px;
      right: 24px;
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--white);
      font-size: 22px;
      font-weight: 700;
      cursor: pointer;
      box-shadow: var(--shadow-lg);
      transition: var(--transition-normal);
      border: none;
      z-index: 1000;
      text-decoration: none;
    } */

    /* .floating-add:hover {
      transform: scale(1.1) rotate(90deg);
      box-shadow: var(--shadow-xl);
      color: var(--white);
    } */

    /* COMPACT MESSAGE SYSTEM */
    .message {
      padding: 12px 16px;
      border-radius: var(--radius-md);
      margin-bottom: 16px;
      font-weight: 600;
      display: none;
      opacity: 0;
      transition: var(--transition-normal);
      border: 1px solid transparent;
      box-shadow: var(--shadow-sm);
      font-size: 13px;
    }

    .message.success {
      background: linear-gradient(135deg, rgba(82, 196, 26, 0.1) 0%, rgba(82, 196, 26, 0.05) 100%);
      color: var(--success);
      border-color: rgba(82, 196, 26, 0.2);
    }

    .message.error {
      background: linear-gradient(135deg, var(--action-delete-light) 0%, rgba(139, 115, 85, 0.05) 100%);
      color: var(--action-delete);
      border-color: var(--action-delete-border);
    }

    .message.info { /* Added for info messages from email redirect */
      background: linear-gradient(135deg, rgba(23, 162, 184, 0.1) 0%, rgba(23, 162, 184, 0.05) 100%);
      color: var(--info);
      border-color: rgba(23, 162, 184, 0.2);
    }

    .message.show {
      display: block;
      opacity: 1;
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

    /* COMPACT MODAL STYLES */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(33, 37, 41, 0.5);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      visibility: hidden;
      transition: var(--transition-normal);
    }

    .modal.show {
      opacity: 1;
      visibility: visible;
    }

    .modal-content {
      background: var(--white);
      margin: auto;
      padding: 24px;
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-xl);
      max-width: 360px;
      width: 90%;
      text-align: center;
      transform: translateY(-20px);
      transition: var(--transition-normal);
      border: 1px solid var(--gray-200);
      position: relative;
      overflow: hidden;
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

    .modal.show .modal-content {
      transform: translateY(0);
    }

    .modal-content h3 {
      margin-bottom: 12px;
      color: var(--gray-800);
      font-size: 18px;
      font-weight: 700;
    }

    .modal-content p {
      margin-bottom: 24px;
      color: var(--gray-600);
      font-size: 14px;
      line-height: 1.4;
    }

    .modal-buttons {
      display: flex;
      justify-content: center;
      gap: 12px;
    }

    .modal-buttons .btn-cancel,
    .modal-buttons .btn-confirm { /* Changed to generic .btn-confirm */
      padding: 10px 20px;
      border: none;
      border-radius: var(--radius-md);
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      transition: var(--transition-normal);
      font-family: inherit;
      box-shadow: var(--shadow-sm);
    }

    .modal-buttons .btn-cancel {
      background: var(--gray-200);
      color: var(--gray-700);
      border: 1px solid var(--gray-300);
    }

    .modal-buttons .btn-cancel:hover {
      background: var(--gray-300);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .modal-buttons .btn-confirm { /* Generic confirm button */
      background: var(--khaki-primary); /* Changed to khaki primary */
      color: var(--white);
    }
    
    .modal-buttons .btn-confirm.danger { /* For actions like delete/logout */
        background: var(--danger); /* Use danger color */
        color: var(--white);
    }

    .modal-buttons .btn-confirm:hover {
      background: var(--khaki-dark); /* Darker khaki on hover */
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }
    
    .modal-buttons .btn-confirm.danger:hover {
        background: var(--action-delete-dark);
    }


    /* COMPACT RESPONSIVE DESIGN */
    @media (max-width: 1024px) {
      .sidebar {
        width: 200px;
      }
      
      .main-content {
        padding: 20px;
        margin-left: 200px; /* Adjusted margin for smaller sidebar */
      }
      
      .visitors-grid {
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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

      .visitors-grid {
        grid-template-columns: 1fr;
        gap: 12px;
        /* margin-bottom: 70px; Adjusted margin */
      }

      .visitor-header {
        flex-direction: column;
        text-align: center;
        align-items: center;
        gap: 12px;
      }

      .visitor-actions {
        flex-direction: column;
        gap: 8px;
      }

      /* .floating-add {
        bottom: 16px;
        right: 16px;
        width: 48px;
        height: 48px;
        font-size: 18px;
      } */

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
      
      .visitor-avatar {
        width: 48px;
        height: 48px;
        font-size: 18px;
      }
      
      .content-header h1 {
        font-size: 22px;
      }

      .visitors-grid {
        grid-template-columns: 1fr;
        gap: 12px;
        /* margin-bottom: 60px; Adjusted margin */
      }

      /* .floating-add {
        bottom: 12px;
        right: 12px;
        width: 44px;
        height: 44px;
        font-size: 16px;
      } */
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

    /* @keyframes float {
      0%, 100% {
        transform: translateY(0px);
      }
      50% {
        transform: translateY(-4px);
      }
    } */

    /* .floating-add {
      animation: float 2.5s ease-in-out infinite;
    } */

    /* .floating-add:hover {
      animation: none;
    } */

    /* FOCUS STYLES FOR ACCESSIBILITY */
    .btn:focus,
    /* .floating-add:focus, Removed focus for floating add button */
    .sidebar nav li a:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES */
    .btn:disabled,
    .modal-buttons button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* PRINT STYLES */
    @media print {
      .sidebar,
      .visitor-actions,
      .floating-add,
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
          <li class="active"><a href="T_VisitorsList.php"><span></span> Visitors List</a></li>
          <li><a href="T_Pending.php"><span></span> Pending <span class="badge" id="pendingBadgeVisitorsList"><?php echo $pending_badge_count; ?></span></a></li>
          <li><a href="T_MemberRegistration.php"><span></span> Member Registration</a></li>
        </ul>
      </nav>
      <a href="#" class="logout" id="logoutLink">Log out</a>
    </aside>

    <main class="main-content">
      <div class="content-header">
        <h1>Visitor Management</h1>
        <p>Manage and track all registered visitors</p>
      </div>

      <div id="messageContainer">
        <?php if ($flash_message): ?>
            <div class='message <?php echo $flash_message_type; ?> show'><?php echo $flash_message; ?></div>
        <?php endif; ?>
      </div>

      <h2 class="section-header">Accepted Visitors</h2>
      <div class="visitors-grid" id="acceptedVisitorsGrid">
        <?php echo $accepted_visitors_html; ?>
      </div>

      <h2 class="section-header">Rejected Visitors</h2>
      <div class="visitors-grid" id="rejectedVisitorsGrid">
        <?php echo $rejected_visitors_html; ?>
      </div>

      </main>
  </div>

  <div id="logoutModal" class="modal">
    <div class="modal-content">
      <h3>Confirm Logout</h3>
      <p>Are you sure you want to log out?</p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="cancelLogout">Cancel</button>
        <button class="btn-confirm danger" id="confirmLogout">Log out</button>
      </div>
    </div>
  </div>

  <div id="genericConfirmModal" class="modal">
    <div class="modal-content">
      <h3 id="genericConfirmModalTitle"></h3>
      <p id="genericConfirmModalMessage"></p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="genericCancelBtn">Cancel</button>
        <button class="btn-confirm" id="genericConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>

  <script>
    // Get references to DOM elements
    const acceptedVisitorsGrid = document.getElementById('acceptedVisitorsGrid');
    const rejectedVisitorsGrid = document.getElementById('rejectedVisitorsGrid'); // New
    const messageContainer = document.getElementById('messageContainer');
    const logoutLink = document.getElementById('logoutLink');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogoutBtn = document.getElementById('confirmLogout');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    const pendingBadgeVisitorsList = document.getElementById('pendingBadgeVisitorsList');

    // Generic Confirmation Modal Elements
    const genericConfirmModal = document.getElementById('genericConfirmModal');
    const genericConfirmModalTitle = document.getElementById('genericConfirmModalTitle');
    const genericConfirmModalMessage = document.getElementById('genericConfirmModalMessage');
    const genericConfirmBtn = document.getElementById('genericConfirmBtn');
    const genericCancelBtn = document.getElementById('genericCancelBtn');
    let currentConfirmAction = null; // To store the callback function for the current confirmation

    /**
     * Displays a generic confirmation modal.
     * @param {string} title - The title for the modal.
     * @param {string} message - The message for the modal.
     * @param {function} confirmCallback - The function to execute if confirmed.
     * @param {boolean} isPositiveAction - If true, confirm button is primary. If false, it's destructive (red).
     */
    function showConfirmModal(title, message, confirmCallback, isPositiveAction = true) {
        genericConfirmModalTitle.textContent = title;
        genericConfirmModalMessage.textContent = message;
        genericConfirmBtn.className = 'btn-confirm'; // Reset classes
        if (!isPositiveAction) {
            genericConfirmBtn.classList.add('danger');
        }
        currentConfirmAction = confirmCallback;
        genericConfirmModal.classList.add('show');
    }

    // Event listener for generic confirm button
    genericConfirmBtn.addEventListener('click', () => {
        if (currentConfirmAction) {
            currentConfirmAction(); // Execute the stored callback
        }
        genericConfirmModal.classList.remove('show');
        currentConfirmAction = null; // Clear the callback
    });

    // Event listener for generic cancel button
    genericCancelBtn.addEventListener('click', () => {
        genericConfirmModal.classList.remove('show');
        currentConfirmAction = null; // Clear the callback
    });

    // Close generic modal if clicked outside content
    genericConfirmModal.addEventListener('click', function(e) {
        if (e.target === genericConfirmModal) {
            genericConfirmModal.classList.remove('show');
            currentConfirmAction = null;
        }
    });


    /**
     * Displays a temporary message to the user.
     * @param {string} text - The message content.
     * @param {'success'|'error'|'info'|'warning'} type - The type of message (determines styling).
     */
    function showMessage(text, type = 'success') {
      messageContainer.innerHTML = ''; // Clear previous messages
      const message = document.createElement('div');
      message.className = `message ${type}`;
      message.textContent = text;

      messageContainer.appendChild(message);

      // Show the message with a fade-in effect
      setTimeout(() => {
        message.classList.add('show');
      }, 10); // Small delay to allow CSS transition

      // Hide the message after 3 seconds with a fade-out effect
      setTimeout(() => {
        message.classList.remove('show');
        setTimeout(() => {
          if (message.parentNode) {
            message.parentNode.removeChild(message); // Remove from DOM after transition
          }
        }, 250); // Match CSS transition duration
      }, 3000);
    }

    /**
     * Sends an AJAX request to delete a visitor.
     * @param {string} visitorId - The ID of the visitor to delete.
     */
    function deleteVisitor(visitorId) {
        fetch('process_visitor_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete_visitor&visitor_id=${visitorId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Remove the card from the DOM
                const cardToRemove = document.querySelector(`.visitor-card[data-visitor-id='${visitorId}']`);
                if (cardToRemove) {
                    cardToRemove.style.transition = 'all 0.25s ease';
                    cardToRemove.style.transform = 'scale(0.95)';
                    cardToRemove.style.opacity = '0';
                    setTimeout(() => {
                        cardToRemove.remove();
                        showMessage(data.message, 'success');
                        // No need for full reload, update relevant grid or empty state
                        updateGridEmptyStates(); 
                        // Update badge count if PHP response includes it, or reload for simplicity
                        if (data.new_pending_count !== undefined) {
                            pendingBadgeVisitorsList.textContent = data.new_pending_count;
                        } else {
                            // Consider a softer update or advise user to refresh if count is critical and not returned
                            // For now, a full reload is a simple fallback if count is critical and not returned.
                            // However, it's better to update just the badge if possible.
                        }
                    }, 250);
                }
            } else {
                showMessage(data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error deleting visitor:', error);
            showMessage('An error occurred while deleting the visitor.', 'error');
        });
    }

    /**
     * Adds a visitor as a permanent member and removes the card from the list.
     * @param {string} visitorId - The ID of the visitor to add.
     * @param {string} visitorName - The name of the visitor.
     */
    function addAsMember(visitorId, visitorName) {
        // Find the specific 'Add as Member' button for this visitor
        const addButton = document.querySelector(`.visitor-card[data-visitor-id='${visitorId}'] .btn-add`);
        if (addButton) {
            addButton.disabled = true; // Disable button immediately to prevent double clicks
            addButton.textContent = 'Adding...'; // Provide feedback
        }

        fetch('process_visitor_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=add_as_member&visitor_id=${visitorId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage(data.message, 'success');
                // Instead of redirecting, remove the card if the action was successful
                const cardToRemove = document.querySelector(`.visitor-card[data-visitor-id='${visitorId}']`);
                if (cardToRemove) {
                    cardToRemove.style.transition = 'all 0.25s ease';
                    cardToRemove.style.transform = 'scale(0.95)';
                    cardToRemove.style.opacity = '0';
                    setTimeout(() => {
                        cardToRemove.remove();
                        updateGridEmptyStates(); // Re-check and display empty state if needed
                    }, 250);
                }
            } else {
                showMessage(data.message, 'error');
                if (addButton) {
                    addButton.disabled = false; // Re-enable button on failure
                    addButton.textContent = 'Add as Member'; // Restore text
                }
            }
        })
        .catch(error => {
            console.error('Error adding member:', error);
            showMessage('The person you are trying to add as a member is already a member.', 'error');
            if (addButton) {
                addButton.disabled = false; // Re-enable button on network error
                addButton.textContent = 'Add as Member'; // Restore text
            }
        });
    }

    // Function to check and display empty state messages for grids
    function updateGridEmptyStates() {
        const checkAndSetEmptyState = (gridElement, messageHtml) => {
            if (gridElement.children.length === 0) {
                // If there's an existing empty state div, update it. Otherwise, create one.
                let emptyStateDiv = gridElement.nextElementSibling; // Assuming empty state is right after grid
                if (!emptyStateDiv || !emptyStateDiv.classList.contains('empty-state')) {
                    emptyStateDiv = document.createElement('div');
                    emptyStateDiv.className = 'empty-state';
                    gridElement.parentNode.insertBefore(emptyStateDiv, gridElement.nextSibling);
                }
                emptyStateDiv.innerHTML = messageHtml;
                emptyStateDiv.style.display = 'block';
            } else {
                // Hide empty state if there are cards
                let emptyStateDiv = gridElement.nextElementSibling;
                if (emptyStateDiv && emptyStateDiv.classList.contains('empty-state')) {
                    emptyStateDiv.style.display = 'none';
                }
            }
        };

        checkAndSetEmptyState(acceptedVisitorsGrid, `
            <h3>No Accepted Visitors Yet</h3>
            <p>Approved visitor requests will appear here.</p>
        `);
        checkAndSetEmptyState(rejectedVisitorsGrid, `
            <h3>No Rejected Visitors Yet</h3>
            <p>Rejected visitor requests will appear here.</p>
        `);
    }

    // --- Logout Modal Event Listeners ---
    if (logoutLink) {
        logoutLink.addEventListener('click', (e) => {
            e.preventDefault(); // Prevent default link behavior
            if (logoutModal) logoutModal.classList.add('show'); // Show the modal
        });
    }
    if (confirmLogoutBtn) {
        confirmLogoutBtn.addEventListener('click', () => {
            document.body.style.transition = 'opacity 0.25s ease';
            document.body.style.opacity = '0'; // Fade out body
            setTimeout(() => {
                window.location.href = 'logout.php'; // Redirect to logout page
            }, 400); // Wait for fade-out
        });
    }
    if (cancelLogoutBtn) {
        cancelLogoutBtn.addEventListener('click', () => {
            if (logoutModal) logoutModal.classList.remove('show'); // Hide the modal
        });
    }

    // Close modal if clicked outside content
    const modals = [logoutModal];
    modals.forEach(modal => {
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.classList.remove('show');
                }
            });
        }
    });

    // --- Page Initialization on DOMContentLoaded ---
    document.addEventListener('DOMContentLoaded', function() {
      // Highlight active sidebar link
      const currentPage = window.location.pathname.split("/").pop();
      document.querySelectorAll('.sidebar nav li a').forEach(link => {
          const linkPage = link.getAttribute('href');
          if (linkPage === currentPage) {
              link.closest('li').classList.add('active');
          } else {
              link.closest('li').classList.remove('active');
          }
      });

      // Auto-hide flash messages after 4 seconds
      const flashMessage = document.querySelector('.message.show');
      if (flashMessage) {
        setTimeout(() => {
          flashMessage.classList.remove('show');
        }, 4000);
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

      // Initially update empty states
      updateGridEmptyStates();
    });
  </script>
</body>
</html>