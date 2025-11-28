<?php
// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kolkata');
// NOTE: 'db_connect.php' is assumed to exist in the same directory and handles $conn establishment.
require_once 'db_connect.php';

// --- Logic Functions ---

/**
 * Redirects the user to a given URL and terminates script execution.
 * @param string $url The destination URL.
 */
function redirect($url) { header("Location: " . $url); exit(); }

/**
 * Retrieves a system setting value by its key from tbl_settings.
 * @param mysqli $conn Database connection object.
 * @param string $key The setting key.
 * @return string|null The setting value or null if not found.
 */
function get_setting($conn, $key) {
    $stmt = $conn->prepare("SELECT setting_value FROM tbl_settings WHERE setting_key = ?");
    if (!$stmt) {
        // Handle preparation error
        error_log("Failed to prepare get_setting statement: " . $conn->error);
        return null;
    }
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result();
    return ($row = $result->fetch_assoc()) ? $row['setting_value'] : null;
}

// --- ID Generation Helper ---

/**
 * Generates a random alphanumeric string.
 * @param int $length The desired length of the string.
 * @return string The random alphanumeric string.
 */
function generate_random_alphanumeric($length = 6) {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $res = '';
    for ($i = 0; $i < $length; $i++) {
        $res .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    return $res;
}

/**
 * Generates a unique Fine ID (UID) based on institution/library initials.
 * Format: {INST}/{LIB}/FINE/{6-CHAR-CODE}
 * @param mysqli $conn Database connection object.
 * @return string The unique fine ID.
 */
function generate_fine_uid($conn) {
    $inst = get_setting($conn, 'institution_initials') ?: 'INS';
    $lib = get_setting($conn, 'library_initials') ?: 'LIB';
    $prefix = strtoupper("$inst/$lib/FINE/");
    
    do {
        $code = generate_random_alphanumeric(6);
        $uid = $prefix . $code;
        $stmt = $conn->prepare("SELECT fine_id FROM tbl_fines WHERE fine_uid = ?");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
    } while ($stmt->get_result()->num_rows > 0);
    
    return $uid;
}

// --- Book Utility Functions ---

/**
 * Generates a unique prefix for a new book UID.
 * Format: {INST}/{LIB}/{6-CHAR-CODE}
 * @param mysqli $conn Database connection object.
 * @return string The book UID prefix.
 */
function generate_book_uid_prefix($conn) {
    $inst = get_setting($conn, 'institution_initials') ?: 'INS';
    $lib = get_setting($conn, 'library_initials') ?: 'LIB';
    $unique = generate_random_alphanumeric(6);
    return strtoupper("$inst/$lib/$unique");
}

// --- Auth Functions ---

/**
 * Checks if an admin is logged in.
 * @return bool True if logged in, false otherwise.
 */
function is_admin_logged_in() { return isset($_SESSION['admin_id']); }

/**
 * Redirects to login page if admin is not logged in.
 */
function require_admin_login() { if (!is_admin_logged_in()) redirect('login.php'); }

/**
 * Checks if a member is logged in.
 * @return bool True if logged in, false otherwise.
 */
function is_member_logged_in() { return isset($_SESSION['member_id']); }

/**
 * Redirects to login page if member is not logged in.
 */
function require_member_login() { if (!is_member_logged_in()) redirect('login.php'); }

/**
 * Checks if the current page matches the given page name for active state.
 * @param string $page_name The name of the page file (e.g., 'index.php').
 * @return string 'active' if it matches, empty string otherwise.
 */
function is_active_page($page_name) { return basename($_SERVER['PHP_SELF']) == $page_name ? 'active' : ''; }

/**
 * Calculates the fine amount based on overdue days.
 * @param mysqli $conn Database connection object.
 * @param string $due_date The book's due date (Y-m-d).
 * @param string $return_date The actual return date (Y-m-d).
 * @return float The calculated fine amount.
 */
function calculate_fine($conn, $due_date, $return_date) {
    $fine_per_day = (float)get_setting($conn, 'fine_per_day');
    $due = new DateTime($due_date);
    $return = new DateTime($return_date);
    if ($return > $due) {
        $interval = $return->diff($due);
        return $interval->days * $fine_per_day;
    }
    return 0.00;
}

/**
 * Checks if the currently logged-in admin is a super admin.
 * @param mysqli $conn Database connection object.
 * @return bool True if super admin, false otherwise.
 */
function is_super_admin($conn) {
    if (!is_admin_logged_in()) return false;
    $stmt = $conn->prepare("SELECT is_super_admin FROM tbl_admin WHERE admin_id = ?");
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    return ($row = $stmt->get_result()->fetch_assoc()) ? $row['is_super_admin'] == 1 : false;
}

// --- UI / Branding Functions ---

/**
 * Generates the HTML for the institution and library branding (logo/names).
 * @param mysqli $conn Database connection object.
 * @param string $context 'admin' or 'user' for different styling contexts.
 * @return string HTML content for branding.
 */
function get_branding_html($conn, $context = 'admin') {
    $inst_name = htmlspecialchars(get_setting($conn, 'institution_name') ?? 'Institution');
    $lib_name = htmlspecialchars(get_setting($conn, 'library_name') ?? 'Library Management');
    $logo_path = get_setting($conn, 'institution_logo');
    
    // Determine path prefix (for admin pages nested in /admin/, logo path needs adjustment)
    $path_prefix = strpos($_SERVER['PHP_SELF'], '/admin/') !== false ? '../' : '';
    $full_path = $path_prefix . $logo_path;

    $text_color = ($context == 'admin') ? '#ffffff' : '#1e293b';
    $sub_text = ($context == 'admin') ? 'rgba(255,255,255,0.7)' : '#64748b';

    // 1. Full Brand HTML (Visible when Expanded)
    $html = '<div class="brand-full" style="display:flex; align-items:center; gap: 12px; transition: opacity 0.2s;">';
    if (!empty($logo_path) && file_exists($full_path)) {
        $html .= '<img src="' . $full_path . '" alt="Logo" style="height: 40px; width: auto; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));">';
    } else {
        $html .= '<div style="background: rgba(255,255,255,0.2); backdrop-filter: blur(4px); width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; color: '.$text_color.'; border: 1px solid rgba(255,255,255,0.2);">
                        <i class="fas fa-book-open"></i>
                    </div>';
    }
    $html .= '<div style="line-height: 1.2; white-space: nowrap; overflow: hidden;">
                <div style="font-weight: 700; font-size: 15px; color: '.$text_color.';">'.$inst_name.'</div>
                <div style="font-size: 12px; color: '.$sub_text.';">'.$lib_name.'</div>
              </div>';
    $html .= '</div>';

    // 2. Icon Only HTML (Visible when Collapsed - Admin only)
    if ($context == 'admin') {
        $html .= '<div class="brand-icon" style="display:none; justify-content:center; align-items:center; width:100%;">';
        if (!empty($logo_path) && file_exists($full_path)) {
            $html .= '<img src="' . $full_path . '" alt="Logo" style="height: 35px; width: auto;">';
        } else {
             $html .= '<i class="fas fa-book-open" style="font-size: 24px; color: '.$text_color.';"></i>';
        }
        $html .= '</div>';
    }

    return $html;
}

/**
 * Generates the HTML header and sidebar for the admin dashboard.
 * Includes necessary CSS and branding.
 * @param string $title The title of the current page.
 */
function admin_header($title) {
    global $conn;
    $admin_name = htmlspecialchars($_SESSION['admin_full_name'] ?? 'Admin');
    $admin_id = $_SESSION['admin_id'] ?? 0;
    $branding = get_branding_html($conn, 'admin');

    // --- Fetch Logo for Background Watermark / Favicon ---
    $bg_logo_path = get_setting($conn, 'institution_logo');
    $full_bg_logo_path = (!empty($bg_logo_path) && file_exists('../' . $bg_logo_path)) ? '../' . $bg_logo_path : '';
    $favicon_path = (!empty($bg_logo_path)) ? '../' . $bg_logo_path : '';

    // --- FETCH ASSIGNED LIBRARY & FULL ADMIN PROFILE DETAILS ---
    $assigned_lib = "All Libraries"; 
    $profile_data = ['username' => 'N/A', 'full_name' => 'N/A', 'role' => 'N/A', 'library' => 'All Libraries'];
    
    if ($admin_id > 0) {
        $stmt_p = $conn->prepare("
            SELECT a.username, a.full_name, a.is_super_admin, l.library_name 
            FROM tbl_admin a 
            LEFT JOIN tbl_libraries l ON a.library_id = l.library_id 
            WHERE a.admin_id = ?
        ");
        $stmt_p->bind_param("i", $admin_id);
        $stmt_p->execute();
        $res_p = $stmt_p->get_result();
        if ($row_p = $res_p->fetch_assoc()) {
            $profile_data['username'] = $row_p['username'];
            $profile_data['full_name'] = $row_p['full_name'];
            $profile_data['role'] = ($row_p['is_super_admin'] == 1) ? 'Super Admin' : 'Librarian/Admin';
            $profile_data['library'] = $row_p['library_name'] ?? 'All Libraries (Global)';
            
            // Set the header display variable
            if (!empty($row_p['library_name'])) {
                $assigned_lib = htmlspecialchars($row_p['library_name']);
            }
        }
    }

    // Flash Message Logic
    $flash_msg = '';
    if (isset($_SESSION['flash_success'])) {
        $flash_msg = "<script>alert('✅ " . $_SESSION['flash_success'] . "');</script>";
        unset($_SESSION['flash_success']);
    }
    if (isset($_SESSION['flash_error'])) {
        $flash_msg = "<script>alert('⚠️ " . $_SESSION['flash_error'] . "');</script>";
        unset($_SESSION['flash_error']);
    }

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . $title . ' | LMS Admin</title>
    <link rel="icon" type="image/png" href="' . $favicon_path . '">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --glass-border: rgba(255, 255, 255, 0.2);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
            --primary: #6366f1;
            --text-main: #1e293b;
            --sidebar-width: 270px;
            --sidebar-collapsed-width: 80px;
        }

        body {
            font-family: "Plus Jakarta Sans", sans-serif;
            margin: 0; padding: 0;
            background: radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                        radial-gradient(at 50% 100%, hsla(225,39%,25%,1) 0, transparent 50%), 
                        radial-gradient(at 100% 0%, hsla(339,49%,25%,1) 0, transparent 50%),
                        #0f172a;
            background-attachment: fixed; background-size: cover;
            color: var(--text-main); min-height: 100vh; overflow-x: hidden;
        }

        .wrapper { display: flex; min-height: 100vh; transition: all 0.3s ease; }

        /* SIDEBAR STYLES */
        .sidebar {
            width: var(--sidebar-width);
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
            border-right: 1px solid rgba(255, 255, 255, 0.08);
            color: #fff; position: fixed; height: 100vh;
            display: flex; flex-direction: column; z-index: 1000;
            transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }

        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }

        .sidebar-header {
            padding: 15px 20px; height: 60px;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(to bottom, rgba(255,255,255,0.05), transparent);
            position: relative;
        }

        .toggle-btn {
            background: transparent; border: none; color: rgba(255,255,255,0.6); cursor: pointer;
            font-size: 1.2rem; padding: 5px; transition: 0.2s;
        }
        .toggle-btn:hover { color: #fff; }

        .sidebar.collapsed .brand-full { display: none !important; }
        .sidebar.collapsed .brand-icon { display: flex !important; }
        .sidebar.collapsed .toggle-btn { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%) rotate(180deg); }

        .sidebar-menu { list-style: none; padding: 20px 10px; margin: 0; overflow-y: auto; overflow-x: hidden; flex-grow: 1; }
        
        .menu-label {
            font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1.5px;
            color: rgba(255, 255, 255, 0.4); margin: 25px 0 10px 15px; font-weight: 700;
            white-space: nowrap; opacity: 1; transition: opacity 0.2s;
        }
        .sidebar.collapsed .menu-label { opacity: 0; height: 0; margin: 0; overflow: hidden; }

        .sidebar-menu li a {
            display: flex; align-items: center; gap: 15px; padding: 14px 18px;
            color: rgba(255, 255, 255, 0.7); text-decoration: none; border-radius: 12px;
            transition: all 0.2s ease; font-size: 0.95rem; margin-bottom: 5px;
            border: 1px solid transparent; white-space: nowrap;
        }
        .sidebar-menu li a i { width: 20px; text-align: center; font-size: 1.1rem; transition: font-size 0.2s; }
        
        .sidebar.collapsed .sidebar-menu li a { justify-content: center; padding: 14px 0; }
        .sidebar.collapsed .sidebar-menu li a span { display: none; }
        .sidebar.collapsed .sidebar-menu li a i { font-size: 1.3rem; width: auto; }

        .sidebar-menu li a:hover { background: rgba(255, 255, 255, 0.1); color: #fff; }
        .sidebar-menu li a.active {
            background: rgba(99, 102, 241, 0.25); border: 1px solid rgba(99, 102, 241, 0.4);
            color: #fff; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2); backdrop-filter: blur(5px);
        }

        .sidebar.collapsed .sidebar-menu li a:hover::after {
            content: attr(title); position: absolute; left: 70px; background: #1e293b;
            color: #fff; padding: 5px 10px; border-radius: 4px; font-size: 0.8rem;
            white-space: nowrap; z-index: 2000; box-shadow: 0 2px 10px rgba(0,0,0,0.2); pointer-events: none;
        }

        .content {
            margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width));
            display: flex; flex-direction: column;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .content.expanded {
            margin-left: var(--sidebar-collapsed-width); width: calc(100% - var(--sidebar-collapsed-width));
        }

        .navbar {
            background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.5); 
            height: 70px; padding: 0 30px;
            display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 900;
        }
        .page-title { font-size: 1.4rem; font-weight: 700; color: #1e293b; margin: 0; }
        
        .user-dropdown {
            background: rgba(255, 255, 255, 0.5); border: 1px solid rgba(255, 255, 255, 0.8);
            padding: 8px 15px; border-radius: 30px; display: flex; align-items: center; gap: 12px;
            cursor: pointer; transition: 0.2s;
        }
        .user-dropdown:hover { background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        
        .user-avatar {
            width: 35px; height: 35px; background: linear-gradient(135deg, #6366f1, #a855f7);
            color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 14px;
        }
        
        .page-content { 
            padding: 30px; position: relative; z-index: 1; 
            min-height: calc(100vh - 70px); 
        }

        .page-content::before {
            content: ""; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 80%; height: 80%; background-image: url("' . $full_bg_logo_path . '");
            background-repeat: no-repeat; background-position: center center; background-size: contain;
            opacity: 0.3; z-index: -1; pointer-events: none;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.65); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.6); box-shadow: var(--glass-shadow);
            border-radius: 20px; padding: 30px;
        }

        /* --- PROFILE MODAL STYLES --- */
        .profile-modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(5px);
            z-index: 2000; display: none; justify-content: center; align-items: center;
            animation: fadeInModal 0.3s;
        }
        .profile-modal {
            background: rgba(255, 255, 255, 0.95); width: 90%; max-width: 500px;
            border-radius: 20px; padding: 30px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative; border: 1px solid rgba(255,255,255,0.5);
            animation: slideUpModal 0.3s;
        }
        .pm-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; }
        .pm-header h3 { margin: 0; color: #1e293b; font-size: 1.3rem; display:flex; align-items:center; gap:10px; }
        .pm-close { background: transparent; border: none; font-size: 1.5rem; cursor: pointer; color: #64748b; }
        
        .pm-section-title { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px; color: #64748b; font-weight: 700; margin: 15px 0 10px; }
        
        .pm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .pm-group label { display: block; font-size: 0.85rem; color: #475569; margin-bottom: 5px; font-weight: 600; }
        .pm-static { 
            background: #f1f5f9; padding: 10px 12px; border-radius: 8px; 
            color: #334155; font-size: 0.95rem; border: 1px solid #e2e8f0;
        }
        
        .pm-input-wrap { position: relative; }
        .pm-input { 
            width: 100%; padding: 10px 40px 10px 12px; border-radius: 8px; border: 1px solid #cbd5e1;
            font-size: 0.95rem; box-sizing: border-box; transition: 0.3s;
        }
        .pm-input:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        .pm-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; }
        .pm-toggle:hover { color: #6366f1; }

        .pm-btn {
            width: 100%; background: #6366f1; color: white; border: none; padding: 12px;
            border-radius: 10px; font-weight: 600; font-size: 1rem; cursor: pointer;
            transition: 0.2s; margin-top: 10px;
        }
        .pm-btn:hover { background: #4f46e5; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }

        @keyframes fadeInModal { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUpModal { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); width: var(--sidebar-width); }
            .content { margin-left: 0 !important; width: 100% !important; }
            .toggle-btn { display: none; }
        }
    </style>
</head>
<body>
    ' . $flash_msg . '
    
    <div id="profileModal" class="profile-modal-overlay">
        <div class="profile-modal">
            <div class="pm-header">
                <h3><i class="fas fa-user-shield" style="color:#6366f1;"></i> Admin Profile</h3>
                <button class="pm-close" onclick="closeProfileModal()">&times;</button>
            </div>
            
            <form action="update_profile.php" method="POST">
                <div class="pm-section-title">Read-Only Details</div>
                <div class="pm-grid">
                    <div class="pm-group">
                        <label>Username</label>
                        <div class="pm-static">' . htmlspecialchars($profile_data['username']) . '</div>
                    </div>
                    <div class="pm-group">
                        <label>Full Name</label>
                        <div class="pm-static">' . htmlspecialchars($profile_data['full_name']) . '</div>
                    </div>
                    <div class="pm-group">
                        <label>Role</label>
                        <div class="pm-static">' . htmlspecialchars($profile_data['role']) . '</div>
                    </div>
                    <div class="pm-group">
                        <label>Assigned Library</label>
                        <div class="pm-static">' . htmlspecialchars($profile_data['library']) . '</div>
                    </div>
                </div>

                <div class="pm-section-title" style="color:#6366f1; border-top:1px dashed #e2e8f0; padding-top:10px;">Update Security</div>
                <div class="pm-group" style="margin-bottom:15px;">
                    <label>New Password</label>
                    <div class="pm-input-wrap">
                        <input type="password" name="password" id="newPass" class="pm-input" placeholder="Enter new password">
                        <i class="fas fa-eye pm-toggle" onclick="togglePass(\'newPass\', this)"></i>
                    </div>
                </div>
                <div class="pm-group" style="margin-bottom:15px;">
                    <label>Confirm Password</label>
                    <div class="pm-input-wrap">
                        <input type="password" name="confirm_password" id="confPass" class="pm-input" placeholder="Re-enter password">
                        <i class="fas fa-eye pm-toggle" onclick="togglePass(\'confPass\', this)"></i>
                    </div>
                </div>

                <button type="submit" class="pm-btn">Update Password</button>
            </form>
        </div>
    </div>

    <div class="wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                ' . $branding . '
                <button class="toggle-btn" id="sidebarToggle" onclick="toggleSidebar()">
                    <i class="fas fa-chevron-left"></i>
                </button>
            </div>
            
            <ul class="sidebar-menu">
                <li><a href="index.php" title="Dashboard" class="' . is_active_page('index.php') . '"><i class="fas fa-tachometer-alt"></i> <span>Dashboard</span></a></li>
                <div class="menu-label">Catalog</div>
                <li><a href="books.php" title="Book Management" class="' . is_active_page('books.php') . '"><i class="fas fa-book"></i> <span>Book Management</span></a></li>
                <li><a href="print_labels.php" title="Print Labels" class="' . is_active_page('print_labels.php') . '"><i class="fas fa-print"></i> <span>Print Labels</span></a></li>
                <li><a href="book_copies.php" title="Copy Status" class="' . is_active_page('book_copies.php') . '"><i class="fas fa-barcode"></i> <span>Copy Status</span></a></li>
                <li><a href="reservations.php" title="Reservations" class="' . is_active_page('reservations.php') . '"><i class="fas fa-calendar-check"></i> <span>Reservations</span></a></li>
                <div class="menu-label">Users & Circ</div>
                <li><a href="profile_history.php" title="Profile History" class="' . is_active_page('profile_history.php') . '"><i class="fas fa-address-card"></i> <span>Profile History</span></a></li>
                <li><a href="members.php" title="Members" class="' . is_active_page('members.php') . '"><i class="fas fa-users"></i> <span>Members</span></a></li>
                <li><a href="print_member_labels.php" title="Print Member ID" class="' . is_active_page('print_member_labels.php') . '"><i class="fas fa-id-badge"></i> <span>Print Member ID</span></a></li>
                <li><a href="issue_book.php" title="Issue Book" class="' . is_active_page('issue_book.php') . '"><i class="fas fa-file-signature"></i> <span>Issue Book</span></a></li>
                <li><a href="return_book.php" title="Return Book" class="' . is_active_page('return_book.php') . '"><i class="fas fa-undo-alt"></i> <span>Return Book</span></a></li>
                <div class="menu-label">Admin</div>
                <li><a href="fines.php" title="Fines" class="' . is_active_page('fines.php') . '"><i class="fas fa-money-bill-wave"></i> <span>Fines</span></a></li>
                <li><a href="library_clearance.php" title="Library Clearance" class="' . is_active_page('library_clearance.php') . '"><i class="fas fa-certificate"></i> <span>Library Clearance</span></a></li>
                <li><a href="reports.php" title="Reports" class="' . is_active_page('reports.php') . '"><i class="fas fa-chart-line"></i> <span>Reports</span></a></li>
                <li><a href="system_alerts.php" title="System Alerts" class="' . is_active_page('system_alerts.php') . '"><i class="fas fa-shield-alt"></i> <span>System Alerts</span></a></li>';
    
    if (is_super_admin($conn)) {
        echo '<li><a href="settings.php" title="Settings" class="' . is_active_page('settings.php') . '"><i class="fas fa-cogs"></i> <span>Settings</span></a></li>';
    }
    
    echo '           <li style="margin-top:auto; padding-top:20px; border-top:1px solid rgba(255,255,255,0.1);">
                    <a href="logout.php" title="Logout" style="color:#ef233c;">
                        <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
                    </a>
                </li>
            </ul>
        </aside>
        
        <main class="content" id="mainContent">
            <header class="navbar">
                <h1 class="page-title">' . $title . '</h1>
                
                <div style="display: flex; align-items: center; gap: 20px;">
                    <div style="display: flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.6); padding: 8px 16px; border-radius: 30px; border: 1px solid rgba(0,0,0,0.05);">
                        <i class="fas fa-building" style="color: #6366f1; font-size: 0.9rem;"></i>
                        <span style="font-size: 0.85rem; font-weight: 600; color: #475569;">' . $assigned_lib . '</span>
                    </div>

                    <div class="user-dropdown" onclick="openProfileModal()">
                        <div class="user-avatar">' . strtoupper(substr($admin_name, 0, 1)) . '</div>
                        <span style="font-size:0.9rem; font-weight:600; color:#334155;">' . $admin_name . '</span>
                    </div>
                </div>
            </header>
            <div class="page-content">';
}

/**
 * Generates the HTML closing tags and JavaScript for the admin dashboard.
 */
function admin_footer() {
    echo ' </div> 
        </main> 
    </div>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById("sidebar");
            const content = document.getElementById("mainContent");
            const icon = document.querySelector("#sidebarToggle i");
            
            sidebar.classList.toggle("collapsed");
            content.classList.toggle("expanded");
            
            if (sidebar.classList.contains("collapsed")) {
                icon.classList.remove("fa-chevron-left");
                icon.classList.add("fa-chevron-right");
                localStorage.setItem("sidebarState", "collapsed");
            } else {
                icon.classList.remove("fa-chevron-right");
                icon.classList.add("fa-chevron-left");
                localStorage.setItem("sidebarState", "expanded");
            }
        }
        
        // --- PROFILE MODAL JS ---
        function openProfileModal() {
            document.getElementById("profileModal").style.display = "flex";
        }
        function closeProfileModal() {
            document.getElementById("profileModal").style.display = "none";
        }
        function togglePass(fieldId, icon) {
            const field = document.getElementById(fieldId);
            if (field.type === "password") {
                field.type = "text";
                icon.classList.remove("fa-eye");
                icon.classList.add("fa-eye-slash");
            } else {
                field.type = "password";
                icon.classList.remove("fa-eye-slash");
                icon.classList.add("fa-eye");
            }
        }
        // Close on outside click
        window.addEventListener("click", function(e) {
            const modal = document.getElementById("profileModal");
            if (e.target === modal) { closeProfileModal(); }
        });

        document.addEventListener("DOMContentLoaded", function() {
            if (window.innerWidth <= 768) {
                 const sidebar = document.getElementById("sidebar");
                 const content = document.getElementById("mainContent");
                 sidebar.classList.add("collapsed");
                 content.classList.remove("expanded");
            }
            const state = localStorage.getItem("sidebarState");
            if (state === "collapsed" && window.innerWidth > 768) { 
                toggleSidebar(); 
            }
        });
    </script>
</body> 
</html>';
}
// Add these to includes/functions.php

/**
 * Generates the HTML header and navigation for the member/public user interface.
 * @param string $title The title of the current page.
 */
function user_header($title) {
    global $conn;
    $branding = get_branding_html($conn, 'user');
    
    // --- Fetch Logo for Favicon ---
    $logo_path = get_setting($conn, 'institution_logo');
    $favicon_path = $logo_path; // Favicon path relative to root

    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($title) . '</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="' . $favicon_path . '">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            font-family: "Plus Jakarta Sans", sans-serif; 
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); 
            margin: 0; 
            min-height: 100vh; 
            color: #1e293b; 
        }
        .glass-nav { 
            background: rgba(255, 255, 255, 0.75); 
            backdrop-filter: blur(16px); 
            -webkit-backdrop-filter: blur(16px); 
            border-bottom: 1px solid rgba(255, 255, 255, 0.3); 
            padding: 0 10%; 
            height: 60px; /* Reduced height from 70px */
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            position: sticky; 
            top: 0; 
            z-index: 100; 
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05); 
        }
        .nav-link { 
            text-decoration: none; 
            color: #475569; 
            font-weight: 600; 
            font-size: 0.95rem; 
            padding: 8px 16px; 
            border-radius: 20px; 
            transition: 0.3s; 
        }
        .nav-link:hover { 
            background: rgba(99, 102, 241, 0.1); 
            color: #6366f1; 
        }
        .btn-glass { 
            background: rgba(99, 102, 241, 0.9); 
            color: white; 
            padding: 10px 24px; 
            border-radius: 30px; 
            text-decoration: none; 
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); 
            transition: transform 0.2s; 
            white-space: nowrap;
        }
        .btn-glass:hover { 
            transform: translateY(-2px); 
        }
        .container { 
            max-width: 1200px; 
            margin: 40px auto; 
            padding: 0 20px; 
        }
        
        /* User side menu icons */
        .nav-link i { margin-right: 5px; }

        @media (max-width: 768px) {
            .glass-nav { padding: 0 15px; }
            .nav-link { padding: 6px 10px; font-size: 0.85rem; }
            .btn-glass { padding: 8px 15px; font-size: 0.85rem; }
            .nav-link span { display: none; } /* Hide link text to save space on mobile */
            .nav-link i { margin-right: 0; }
        }
    </style>
</head>
<body>
    <header class="glass-nav">
        ' . $branding . '
        <nav style="display:flex; gap:10px; align-items:center;">
            <a href="index.php" class="nav-link" title="Home"><i class="fas fa-home"></i> <span>Home</span></a>
            <a href="search.php" class="nav-link" title="Search Catalog"><i class="fas fa-search"></i> <span>Search Catalog</span></a>
            <a href="reservations.php" class="nav-link" title="Reservations"><i class="fas fa-calendar-check"></i> <span>Reservations</span></a>
            ' . (is_member_logged_in() ? 
                '<a href="dashboard.php" class="nav-link" title="Dashboard" style="color:#6366f1;"><i class="fas fa-user-circle"></i> <span>Dashboard</span></a> <a href="logout.php" class="nav-link" title="Logout" style="color:#ef4444;"><i class="fas fa-sign-out-alt"></i> <span>Logout</span></a>' 
                : '<a href="login.php" class="btn-glass" title="Login"><i class="fas fa-sign-in-alt"></i> Login</a>') . '
        </nav>
    </header>
    <div class="container">';
}

/**
 * Generates the HTML footer for the member/public user interface.
 */
function user_footer() {
    echo '</div>
    <footer style="text-align: center; padding: 20px; color: #666; font-size: 0.9rem; margin-top: 40px;">
        <p>&copy; ' . date('Y') . ' LMS. All Rights Reserved. Developed & Designed by Pritam Bhaumik</p>
    </footer>
    </body></html>';
}

// --- Email Logic ---
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Adjust paths based on where you put the PHPMailer folder
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

function send_system_email($to_email, $member_name, $subject, $body, $library_name = null) {
    global $conn;

    if (empty($to_email)) return false;
    
    // Fetch System Settings for Email Header
    $institution_name = get_setting($conn, 'institution_name') ?? 'Institution';
    $default_library_name = get_setting($conn, 'library_name') ?? 'Central Library';
    $logo_path = get_setting($conn, 'institution_logo');
    
    // Use provided library name or default
    $final_library_name = $library_name ? $library_name : $default_library_name;
    
    // Determine absolute path for embedded image
    // Assuming the script runs from root or admin, we need to find the physical path
    // __DIR__ is .../includes/
    // logo path in db is uploads/logos/logo...
    $base_dir = dirname(__DIR__); 
    $logo_abs_path = $base_dir . '/' . $logo_path;
    $has_logo = (!empty($logo_path) && file_exists($logo_abs_path));

    // Determine System URL (Best Guess for Link)
    // Protocol
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    // Host
    $host = $_SERVER['HTTP_HOST'];
    // Path - Assuming the project folder is the one containing 'includes'
    // $_SERVER['SCRIPT_NAME'] might be /project/admin/issue_book.php
    // We want /project/
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    // If inside admin, go up one level
    if (strpos($script_dir, '/admin') !== false) {
        $base_url_path = dirname($script_dir);
    } else {
        $base_url_path = $script_dir;
    }
    // Clean up slashes
    $base_url_path = rtrim($base_url_path, '/\\');
    $system_url = $protocol . $host . $base_url_path;


    // --- Construct HTML Template ---
    $html_content = '
    <!DOCTYPE html>
    <html>
    <head>
    <style>
      body { font-family: "Segoe UI", Arial, sans-serif; background-color: #f4f7f6; margin: 0; padding: 0; }
      .email-container { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); border: 1px solid #e0e0e0; }
      .header { background: linear-gradient(135deg, #4361ee, #3f37c9); padding: 30px; text-align: center; color: white; }
      .logo-img { height: 70px; width: 70px; object-fit: contain; background: white; padding: 5px; border-radius: 50%; margin-bottom: 10px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
      .inst-name { font-size: 20px; font-weight: 800; margin: 0; text-transform: uppercase; letter-spacing: 1px; }
      .lib-name { font-size: 14px; opacity: 0.9; margin-top: 5px; font-weight: 500; background: rgba(255,255,255,0.2); display: inline-block; padding: 2px 10px; border-radius: 15px; }
      .content { padding: 40px 30px; color: #333; line-height: 1.6; font-size: 15px; }
      .btn-container { text-align: center; margin-top: 35px; }
      .btn { background-color: #4361ee; color: #ffffff !important; padding: 14px 30px; text-decoration: none; border-radius: 30px; font-weight: bold; display: inline-block; box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3); transition: background 0.3s; }
      .btn:hover { background-color: #3f37c9; }
      .footer { background-color: #f8f9fa; text-align: center; padding: 20px; font-size: 12px; color: #888; border-top: 1px solid #eee; }
    </style>
    </head>
    <body>
      <div class="email-container">
        <div class="header">';
    
    if ($has_logo) {
        $html_content .= '<img src="cid:inst_logo" class="logo-img" alt="Logo">';
    }
    
    $html_content .= '
           <div class="inst-name">' . htmlspecialchars($institution_name) . '</div>
           <div class="lib-name">' . htmlspecialchars($final_library_name) . '</div>
        </div>
        <div class="content">
           ' . $body . '
           <div class="btn-container">
             <a href="' . $system_url . '/index.php" class="btn">Go to My Library Account</a>
           </div>
        </div>
        <div class="footer">
          &copy; ' . date('Y') . ' ' . htmlspecialchars($institution_name) . '. All rights reserved.<br>
          This is an automated system email. Please do not reply directly.
        </div>
      </div>
    </body>
    </html>';

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com'; 
        $mail->SMTPAuth   = true;
        $mail->Username   = 'your-email'; 
        $mail->Password   = 'your-app-password';    
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('lms.librario@gmail.com', $final_library_name . ' System');
        $mail->addAddress($to_email, $member_name);

        // Embed Logo
        if ($has_logo) {
            $mail->addEmbeddedImage($logo_abs_path, 'inst_logo', 'logo.png');
        }

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_content;
        // Plain text fallback (strip tags)
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $body)) . "\n\nLogin here: " . $system_url;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Log error if needed: error_log($mail->ErrorInfo);
        return false;
    }
}


?>