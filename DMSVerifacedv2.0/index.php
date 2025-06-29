<?php
session_start(); // Start the session at the very beginning of the script

// Include the database connection file provided by the user
require_once 'db_connect.php'; // Using the user's provided db_connect.php

// Prepare a select statement to fetch tenant data along with their unit number
// MODIFIED LINE: Directly select 't.known_face_path_1' for the profile picture
$sql = "SELECT t.tenant_id, t.first_name, t.last_name, t.email, t.phone_number, t.known_face_path_1, t.known_face_path_2, t.known_face_path_3, t.unit_id, u.unit_number
        FROM tenants t
        JOIN units u ON t.unit_id = u.unit_id
        ORDER BY CAST(SUBSTRING_INDEX(u.unit_number, ' ', -1) AS UNSIGNED) ASC"; // Order by the numeric part of unit_number ascending

$unit_profiles = []; // Initialize an empty array to hold fetched data

if ($result = $conn->query($sql)) {
    if ($result->num_rows > 0) {
        // Fetch rows and store them in the array
        while ($row = $result->fetch_assoc()) {
            $unit_profiles[] = $row;
        }
    } else {
        // No records found
        $_SESSION['flash_message'] = 'No unit profiles found in the database.';
        $_SESSION['flash_message_type'] = 'info';
    }
    $result->free(); // Free result set
} else {
    // Query execution failed
    $_SESSION['flash_message'] = 'Error retrieving unit profiles: ' . $conn->error;
    $_SESSION['flash_message_type'] = 'danger';
}

// Retrieve and clear session flash messages after processing
$flash_message_display = '';
$flash_message_type_display = '';
if (isset($_SESSION['flash_message'])) {
    $flash_message_display = htmlspecialchars($_SESSION['flash_message']);
    $flash_message_type_display = htmlspecialchars($_SESSION['flash_message_type'] ?? 'info');
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced - Unit Profiles</title>
    <style>
        /* --- GLOBAL STYLES --- */
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #F5F5DC 0%, #F0F0E8 100%); /* Subtle khaki gradient */
            color: #2C2C2C; /* Rich dark text */
            display: flex; /* Changed to flex for fixed sidebar compatibility */
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
            display: flex; /* Added to keep sidebar and content side-by-side */
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
            overflow-x: hidden; /* Ensure no horizontal scrollbar */
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
            border-radius: 20px;
            padding: 35px;
            margin-bottom: 25px;
            box-shadow: 0 12px 35px rgba(107, 91, 79, 0.15), 0 6px 15px rgba(107, 91, 79, 0.1);
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -12px;
            margin-right: -12px;
            margin-bottom: 18px;
        }

        .form-group {
            flex: 1 1 calc(50% - 24px);
            min-width: 240px;
            display: flex;
            flex-direction: column;
            margin: 0 12px 24px 12px;
        }

        .form-group label {
            margin-bottom: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #6B5B4F;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="file"],
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 2px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 12px;
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(107, 91, 79, 0.05);
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
            background: linear-gradient(135deg, #c8ae7a 0%, #c8ae7a 100%); /* Green gradient */
            border-color: #c8ae7a; /* A matching green border */
            color: white; /* Keep text white for good contrast */
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
                flex-direction: column; /* Stack sidebar and content */
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
                margin-left: 0; /* Remove fixed margin-left for mobile */
                width: 100%; /* Ensure content takes full width */
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
                    <li class="active"><a href="index.php"> Unit Profiles</a></li>
                    <li><a href="registration.php"> Account Registration</a></li>
                </ul>
            </nav>
            <a href="logout.php" class="logout-btn">Log out</a>
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
                    // Display flash messages from session
                    if ($flash_message_display) {
                        echo '<div class="flash-message ' . $flash_message_type_display . ' show">' . $flash_message_display . '</div>';
                    }
                    ?>
                </div>

                <h2>Unit Profiles</h2>
                <div id="unitProfilesContent">
                    <?php
                    if (!empty($unit_profiles)) {
                        foreach ($unit_profiles as $profile) {
                            // MODIFIED: Directly use $profile['known_face_path_1']
                            $image_to_display_path = $profile['known_face_path_1']; 

                            // The file_exists check ensures the default placeholder is used if the file is missing or path is bad
                            // htmlspecialchars is for XSS protection when outputting to HTML
                            $profile_pic_src = !empty($image_to_display_path) && file_exists(__DIR__ . '/' . $image_to_display_path) ? htmlspecialchars($image_to_display_path) : 'https://placehold.co/60x60/CCCCCC/000000?text=No+Pic';
                            
                            // Debugging logs - Keep these in for now!
                            error_log("DEBUG-INDEX: Tenant: " . htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']));
                            error_log("DEBUG-INDEX: Path from DB (known_face_path_1): " . (isset($profile['known_face_path_1']) ? $profile['known_face_path_1'] : 'NOT SET IN PROFILE ARRAY'));
                            error_log("DEBUG-INDEX: Full server path (for file_exists): " . __DIR__ . '/' . $image_to_display_path);
                            error_log("DEBUG-INDEX: file_exists() result: " . (file_exists(__DIR__ . '/' . $image_to_display_path) ? 'TRUE' : 'FALSE'));
                            error_log("DEBUG-INDEX: Final img src: " . $profile_pic_src);


                            echo '<div class="tenant-profile-card">';
                            echo '<img src="' . $profile_pic_src . '" alt="Profile Picture" class="profile-pic">';
                            echo '<div class="tenant-info">';
                            echo '<p><strong>Name:</strong> ' . htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) . '</p>';
                            echo '<p><strong>Unit:</strong> ' . htmlspecialchars($profile['unit_number']) . '</p>';
                            echo '<p><strong>Email:</strong> ' . htmlspecialchars($profile['email']) . '</p>';
                            echo '<p><strong>Phone:</strong> ' . htmlspecialchars($profile['phone_number']) . '</p>';
                            echo '</div>';
                            echo '<div class="tenant-actions">';
                            // View Profile link now points to tenant_info.php using the unit_id
                            echo '<a href="tenant_info.php?unit_id=' . htmlspecialchars($profile['unit_id']) . '" class="btn view-profile-btn">View Profile</a>';
                            // Delete button: Added data-tenant-id and data-unit-number for JavaScript
                            echo '<button class="btn delete-btn" data-tenant-id="' . htmlspecialchars($profile['tenant_id']) . '" data-tenant-name="' . htmlspecialchars($profile['first_name'] . ' ' . $profile['last_name']) . '" data-unit-number="' . htmlspecialchars($profile['unit_number']) . '">Delete</button>';
                            echo '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<p>No tenant profiles found.</p>';
                    }
                    ?>
                </div>
            </main>
        </div>
        <div class="overlay" id="overlay"></div>
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.delete-btn');
            const flashMessageContainer = document.getElementById('flashMessageContainer');
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('overlay');
            const body = document.body;

            // Custom logout modal functionality
            function showLogoutModal() {
                const modal = document.getElementById('logoutModal');
                const confirmBtn = modal.querySelector('.logout-modal-btn-confirm');
                const cancelBtn = modal.querySelector('.logout-modal-btn-cancel');
                const closeBtn = modal.querySelector('.logout-modal-close');
                
                // Show modal with animation
                modal.classList.add('show');
                
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
                    modal.classList.remove('show');
                    // Remove event listeners to prevent memory leaks
                    confirmBtn.removeEventListener('click', handleConfirm);
                    cancelBtn.removeEventListener('click', handleCancel);
                    closeBtn.removeEventListener('click', handleCancel);
                    modal.removeEventListener('click', handleOverlayClick);
                    document.removeEventListener('keydown', handleEscape);
                };
                
                // Handle clicking outside modal
                const handleOverlayClick = (e) => {
                    if (e.target === modal) {
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
                modal.addEventListener('click', handleOverlayClick);
                document.addEventListener('keydown', handleEscape);
            }

            function showFlashMessage(message, type) {
                // Clear any existing messages
                flashMessageContainer.innerHTML = '';
                const messageDiv = document.createElement('div');
                messageDiv.className = `flash-message ${type} show`; // Add 'show' class for CSS transition
                messageDiv.textContent = message;
                flashMessageContainer.appendChild(messageDiv);

                // Hide message after 3 seconds
                setTimeout(() => {
                    messageDiv.classList.remove('show');
                    // Remove from DOM after transition completes (adjust timeout to match CSS transition)
                    setTimeout(() => {
                        if (messageDiv.parentNode) {
                            messageDiv.parentNode.removeChild(messageDiv);
                        }
                    }, 500); // Assuming CSS transition is 0.5s
                }, 3000);
            }

            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tenantIdToDelete = this.dataset.tenantId; // Get tenant_id from data attribute
                    const tenantName = this.dataset.tenantName; // Get tenant name for confirmation
                    const unitNumber = this.dataset.unitNumber; // Get unit_number from data attribute

                    if (confirm(`Are you sure you want to delete the tenant ${tenantName} from ${unitNumber} and all associated data? This action cannot be undone.`)) {
                        fetch('process_tenant_action.php', { // AJAX call to new PHP file
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=delete_tenant&tenant_id=${tenantIdToDelete}&unit_number=${unitNumber}`
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                showFlashMessage(data.message, 'success');
                                // Remove the tenant card from the DOM
                                this.closest('.tenant-profile-card').remove();
                            } else {
                                showFlashMessage(data.message, 'danger');
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showFlashMessage('An unexpected error occurred during deletion.', 'danger');
                        });
                    }
                });
            });

            // ENHANCED: Logout button functionality with custom modal
            document.querySelector('.logout-btn').addEventListener('click', function(e) {
                e.preventDefault(); // Prevent the default href navigation
                showLogoutModal();
            });

            // Toggle sidebar visibility
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                menuToggle.classList.toggle('active'); // Animate hamburger icon
                body.classList.toggle('sidebar-active'); // To show/hide overlay
            });

            overlay.addEventListener('click', function() {
                sidebar.classList.remove('active');
                menuToggle.classList.remove('active');
                body.classList.remove('sidebar-active');
            });

            // Handle sidebar active link highlighting (existing from your code)
            const currentPage = window.location.pathname.split("/").pop();
            document.querySelectorAll('.sidebar nav li a').forEach(link => {
                const linkPage = link.getAttribute('href');
                if (linkPage === currentPage) {
                    link.closest('li').classList.add('active');
                } else {
                    link.closest('li').classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>