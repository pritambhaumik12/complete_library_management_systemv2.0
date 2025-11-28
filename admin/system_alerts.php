<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';
$is_access_granted = false; 

// --- NEW LOGIC: Check session timestamp for 5-minute expiry ---
$EXPIRY_TIME = 300; // 5 minutes in seconds

if (isset($_SESSION['security_access_timestamp'])) {
    $time_since_last_auth = time() - $_SESSION['security_access_timestamp'];
    
    if ($time_since_last_auth <= $EXPIRY_TIME) {
        $is_access_granted = true;
    } else {
        // Session expired, force re-authentication
        unset($_SESSION['security_access_timestamp']);
        $error = "Session expired. Please re-verify your password.";
    }
}
// --- END NEW LOGIC ---


// --- SECURITY ACCESS CONTROL: Check on every request ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['security_password'])) {
    $submitted_password = $_POST['security_password'];
    $admin_id = $_SESSION['admin_id'];

    $stmt_check = $conn->prepare("SELECT password FROM tbl_admin WHERE admin_id = ?");
    $stmt_check->bind_param("i", $admin_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $admin_data = $result_check->fetch_assoc();

    // **SECURITY NOTE**: Direct plain-text comparison as per existing system design.
    if ($submitted_password === ($admin_data['password'] ?? '')) {
        // Authentication successful
        $is_access_granted = true; 
        // --- NEW: Set timestamp ---
        $_SESSION['security_access_timestamp'] = time(); 
        // --- END NEW: Set timestamp ---
    } else {
        $error = "Access Denied: Incorrect Password for this section.";
    }
}

// --- PASSWORD CHALLENGE PAGE (Access Denied View) ---
if (!$is_access_granted) {
    admin_header('Security Verification');
?>
<style>
    /* Security Challenge Styles (Keeping the original look for security prompt) */
    .security-lock-container {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        min-height: 70vh;
        padding-top: 50px;
        animation: fadeIn 0.5s ease-out;
    }
    .security-lock-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 20px;
        padding: 40px;
        box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
        max-width: 450px;
        width: 100%;
        text-align: center;
        border: 2px solid #ef4444; 
    }
    .lock-icon {
        font-size: 3.5rem;
        color: #ef4444;
        margin-bottom: 20px;
        background: #fee2e2;
        padding: 20px;
        border-radius: 50%;
        display: inline-block;
    }
    .security-lock-card h2 {
        margin-bottom: 10px;
        color: #1e293b;
    }
    .security-lock-card p {
        color: #64748b;
        margin-bottom: 30px;
    }
    .password-input-group {
        position: relative;
        margin-bottom: 20px;
    }
    .password-input-group input {
        width: 85%;
        padding: 15px 15px 15px 45px;
        border: 2px solid #cbd5e1;
        border-radius: 12px;
        font-size: 1rem;
        transition: 0.3s;
    }
    .password-input-group input:focus {
        border-color: #ef4444;
        box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        outline: none;
    }
    .password-input-group i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
    }
    .btn-verify {
        background: linear-gradient(135deg, #ef4444, #dc2626);
        color: white;
        padding: 14px;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 1rem;
        cursor: pointer;
        width: 100%;
        transition: 0.3s;
    }
    .btn-verify:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(239, 68, 68, 0.4);
    }
    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        padding: 10px;
        border-radius: 8px;
        margin-bottom: 20px;
        border: 1px solid #fecaca;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<div class="security-lock-container">
    <div class="security-lock-card">
        <div class="lock-icon">
            <i class="fas fa-lock"></i>
        </div>
        <h2>Security Check Required</h2>
        <p>This section contains sensitive logs. Please re-enter your admin password to proceed.</p>

        <?php if ($error): ?> 
            <div class="alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> 
        <?php endif; ?>

        <form method="POST">
            <div class="password-input-group">
                <i class="fas fa-key"></i>
                <input type="password" name="security_password" placeholder="Enter Password" required autofocus>
            </div>
            <button type="submit" class="btn-verify">
                <i class="fas fa-shield-alt"></i> Grant Access
            </button>
        </form>
    </div>
</div>

<?php
    admin_footer();
    close_db_connection($conn);
    exit;
}

// --- END OF PASSWORD CHALLENGE ---

// --- MAIN PAGE LOGIC (IF $is_access_granted is true) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate_id'])) {
    $mem_id = (int)$_POST['reactivate_id'];
    $stmt = $conn->prepare("UPDATE tbl_members SET status = 'Active' WHERE member_id = ?");
    $stmt->bind_param("i", $mem_id);
    if ($stmt->execute()) {
        $message = "Member account successfully reactivated.";
    }
}

// Handle GET/POST params after successful verification
$tab = $_GET['tab'] ?? 'present';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search_query = trim($_GET['search'] ?? '');


// --- UPDATED SQL QUERY ---
$sql = "
    SELECT 
        ta.alert_id, ta.message, ta.created_at, ta.book_id,
        tm.member_id, tm.full_name, tm.member_uid, tm.status as member_status, tm.screenshot_violations, tm.email, tm.department,
        tb.title as book_title, tb.author, tb.isbn, tb.category,
        tbc.book_uid as base_book_uid
    FROM 
        tbl_system_alerts ta
    JOIN 
        tbl_members tm ON ta.member_id = tm.member_id
    LEFT JOIN 
        tbl_books tb ON ta.book_id = tb.book_id
    LEFT JOIN 
        tbl_book_copies tbc ON tb.book_id = tbc.book_id AND tbc.book_uid LIKE '%-BASE'
    WHERE 1=1
";

if ($tab === 'present') {
    $sql .= " AND ta.created_at >= NOW() - INTERVAL 1 DAY";
} else {
    $sql .= " AND ta.created_at < NOW() - INTERVAL 1 DAY";
}

if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND DATE(ta.created_at) BETWEEN '$start_date' AND '$end_date'";
}

if (!empty($search_query)) {
    $term = "%" . $conn->real_escape_string($search_query) . "%";
    $sql .= " AND (tm.full_name LIKE '$term' OR tm.member_uid LIKE '$term' OR ta.message LIKE '$term')";
}

$sql .= " ORDER BY ta.created_at DESC";
$result = $conn->query($sql);

admin_header('System Security Alerts');
?>

<style>
    /* Redesign & Enhanced Styles for Main Content - LIGHT GLASS THEME (High Contrast) */
    :root {
        /* INCREASED OPACITY to 80% for better contrast */
        --bg-card-light: rgba(255, 255, 255, 0.8); 
        --bg-input-opaque: rgba(241, 245, 249, 0.9); /* Opaque background for inputs */
        --border-color: #e2e8f0;  
        --glass-border: rgba(255, 255, 255, 0.7); /* Subtle white border for glass */
        
        --accent-primary: #3b82f6; 
        --accent-glow: rgba(59, 130, 246, 0.3);
        --accent-danger: #ef4444; 
        
        --text-main: #1e293b;     
        --text-muted: #64748b;    
    }

    /* 1. Ensure the container itself is transparent (done by removing override in previous step) */
    
    .dashboard-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 20px;
    }

    /* 2. Apply Glass Effect to Cards and Filter Panel (using increased opacity) */
    .glass-card, .stat-card, .filter-panel, .table-wrapper, .modal-box {
        background: var(--bg-card-light) !important; 
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid var(--glass-border) !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
    }
    
    /* --- Stat Cards --- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .stat-card {
        padding: 20px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 15px;
        transition: transform 0.2s;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 25px rgba(0,0,0,0.15); 
    }

    .stat-icon {
        width: 50px; height: 50px;
        border-radius: 10px;
        background: rgba(59, 130, 246, 0.1); 
        color: var(--accent-primary);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.5rem;
    }

    .stat-info h4 { margin: 0; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
    .stat-info span { font-size: 1.5rem; font-weight: 700; color: var(--text-main); }
    
    .stat-card:nth-child(2) .stat-icon { 
        color: var(--accent-danger); 
        background: #fee2e2; 
    }
    .stat-card:nth-child(3) .stat-icon { 
        color: #60a5fa; 
        background: #eff6ff; 
    }


    /* --- Header Titles (Ensuring readability against dark background) --- */
    .dashboard-container + main > header > h1.page-title {
        color: var(--text-main) !important; 
    }
    .dashboard-container > div > h2 {
        color: var(--text-main) !important; 
        text-shadow: none !important;
    }
    .section-header h3 {
        color: var(--text-main) !important;
        text-shadow: none !important;
    }
    
    /* --- Highlight for "Security Monitor" --- */
    .highlight-text {
        padding: 5px 10px;
        border-radius: 8px;
        font-weight: 800;
        /* Using a strong, high-contrast gradient */
        background: linear-gradient(90deg, #fef08a 0%, #3b82f6 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        display: inline;
        font-size: 1.8rem; /* Match H2 size */
        margin-left: 5px; /* Separate from "Security" */
    }


    /* --- Filter Bar --- */
    .filter-panel {
        border-bottom: 1px solid var(--border-color);
        padding: 20px;
        border-radius: 12px 12px 0 0;
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        align-items: center;
        justify-content: space-between;
    }

    .search-group {
        display: flex;
        /* Increased opacity for inputs/groups */
        background: var(--bg-input-opaque); 
        border-radius: 8px;
        padding: 5px 15px;
        align-items: center;
        width: 100%;
        max-width: 400px;
        border: 1px solid transparent;
        transition: 0.3s;
    }

    .search-group:focus-within {
        border-color: var(--accent-primary);
        box-shadow: 0 0 10px var(--accent-glow);
    }

    .search-input, .date-input {
        background: transparent;
        border: none;
        color: var(--text-main); 
        width: 100%;
        padding: 10px;
        outline: none;
    }
    
    .date-input {
        /* Increased opacity for inputs/groups */
        background: var(--bg-input-opaque); 
        border: 1px solid var(--border-color);
        color: var(--text-main); 
        padding: 10px;
        border-radius: 8px;
        color-scheme: light; 
    }

    .btn-glow {
        background: var(--accent-primary);
        color: white;
        font-weight: 700;
        padding: 10px 25px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: 0.3s;
        box-shadow: 0 0 15px rgba(59, 130, 246, 0.2);
    }

    .btn-glow:hover {
        background: #2563eb;
        transform: translateY(-1px);
    }

    /* --- Tabs --- */
    .nav-tabs {
        display: flex;
        gap: 20px;
        margin-bottom: 20px;
    }

    .nav-link {
        color: var(--text-muted);
        text-decoration: none;
        padding-bottom: 10px;
        font-weight: 600;
        border-bottom: 2px solid transparent;
        transition: 0.3s;
    }

    .nav-link:hover { color: var(--text-main); }
    
    .nav-link.active {
        color: var(--accent-primary);
        border-color: var(--accent-primary);
    }

    /* --- Table --- */
    .table-wrapper {
        border-radius: 0 0 12px 12px;
        overflow: hidden;
        border-top: none;
    }

    .dark-table {
        width: 100%;
        border-collapse: collapse;
    }

    .dark-table th {
        background: #e0e7ff; 
        text-align: left;
        padding: 15px 20px;
        color: #4f46e5; 
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        white-space: nowrap;
    }

    .dark-table td {
        padding: 15px 20px;
        border-bottom: 1px solid var(--border-color);
        color: var(--text-main); 
        vertical-align: middle;
    }

    /* Subtle Glass Hover effect on table rows */
    .dark-table tr:not(:first-child):hover {
        /* This is now slightly opaque, consistent with the rest of the elements */
        background: rgba(255, 255, 255, 0.7); 
    }

    /* Status Dots */
    .status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 5px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .status-active { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .status-inactive { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    
    .btn-view-details {
        background: transparent; 
        border: 1px solid var(--text-muted); 
        color: var(--text-muted); 
        padding: 5px 10px; 
        border-radius: 5px; 
        cursor: pointer;
        font-size: 0.85rem;
        transition: 0.2s;
        margin-right: 5px;
    }
    .btn-view-details:hover {
        border-color: var(--accent-primary);
        color: var(--accent-primary);
    }
    
    .btn-unlock {
        background: transparent; 
        border: 1px solid #10b981; 
        color: #10b981; 
        padding: 5px 10px; 
        border-radius: 5px; 
        cursor: pointer;
    }
    .btn-unlock:hover {
        background: #10b981;
        color: white;
    }

    /* Modal Styling */
    .modal-overlay {
        position: fixed; 
        top: 80px; 
        left: 0; 
        width: 100%; 
        height: calc(100% - 80px); 
        /* Increased opacity on modal overlay for better visibility */
        background: rgba(244, 247, 246, 0.85); 
        backdrop-filter: blur(5px);
        display: none; 
        justify-content: center; 
        align-items: flex-start; 
        z-index: 999;
        overflow-y: auto; 
        padding: 20px 0; 
    }

    .modal-box {
        padding: 30px;
        border-radius: 16px;
        width: 100%; 
        max-width: 650px; 
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        margin: 0 auto; 
    }
    
    .modal-box h3 {
        color: var(--text-main); 
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
        margin-top: 0;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr); 
        gap: 15px 20px;
        margin-bottom: 25px;
    }
    
    .detail-section-title {
        grid-column: 1 / -1;
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--accent-primary); 
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 5px;
        margin-bottom: 10px;
        margin-top: 15px;
    }
    
    .detail-item {
        padding: 5px 0;
    }
    .detail-item strong {
        display: block;
        color: var(--text-muted);
        font-size: 0.8rem;
        margin-bottom: 3px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .detail-item span {
        font-size: 1rem;
        color: var(--text-main); 
        font-weight: 500;
        display: block;
        word-wrap: break-word;
    }

    .violation-stat {
        display: flex;
        justify-content: space-around;
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
    }
    .violation-stat div {
        text-align: center;
    }
    .violation-stat .count {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--accent-danger);
    }
    .violation-stat .label {
        font-size: 0.8rem;
        color: var(--text-muted);
    }

    .alert-msg {
        background: #eef2ff; 
        border: 1px solid var(--accent-primary);
        color: var(--accent-primary);
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    /* Buttons in Unlock Modal */
    .modal-overlay button[type="button"] {
        background: #e2e8f0; 
        border: 1px solid var(--border-color); 
        color: #475569; 
        padding: 10px 20px; border-radius: 8px; cursor: pointer; margin-right: 10px;
        font-weight: 600;
    }
    .modal-overlay button[type="button"]:hover {
        background: #cbd5e1;
    }
    
    .modal-overlay button[type="submit"] {
        background: var(--accent-primary);
        color: white;
        padding: 10px 20px; border-radius: 8px; border: none; cursor: pointer;
        font-weight: 600;
    }
    .modal-overlay button[type="submit"]:hover {
        background: #2563eb;
    }

    /* Ensure search icon is visible */
    .search-group i {
        color: var(--text-muted);
    }
    
    .search-group:focus-within i {
        color: var(--accent-primary);
    }
    
</style>

<div class="dashboard-container">
    
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <h2 style="margin: 0; font-size: 1.8rem;">
            <span class="highlight-text">Security Monitor</span>
        </h2>
        
        <div class="nav-tabs" style="margin: 0;">
            <a href="?tab=present" class="nav-link <?php echo $tab === 'present' ? 'active' : ''; ?>">Live Feed</a>
            <a href="?tab=past" class="nav-link <?php echo $tab === 'past' ? 'active' : ''; ?>">Archive</a>
        </div>
    </div>

    <?php if ($message): ?> 
        <div class="alert-msg"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> 
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-info"><h4>System Status</h4><span style="color: #10b981;">Secure</span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="stat-info"><h4>New Alerts</h4><span><?php echo $result->num_rows; ?></span></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="fas fa-users"></i></div>
            <div class="stat-info"><h4>Active Users</h4><span>Monitoring...</span></div>
        </div>
    </div>

    <form method="GET">
        <input type="hidden" name="tab" value="<?php echo $tab; ?>">
        <div class="filter-panel">
            <div class="search-group">
                <i class="fas fa-search" style="color: var(--text-muted);"></i>
                <input type="text" name="search" class="search-input" placeholder="Search ID, Name or Violation..." value="<?php echo htmlspecialchars($search_query); ?>">
            </div>
            
            <div style="display: flex; gap: 10px; align-items: center;">
                <input type="date" name="start_date" class="date-input" value="<?php echo htmlspecialchars($start_date); ?>">
                <span style="color: var(--text-muted);">-</span>
                <input type="date" name="end_date" class="date-input" value="<?php echo htmlspecialchars($end_date); ?>">
                <button type="submit" class="btn-glow">FILTER</button>
            </div>
        </div>
    </form>

    <div class="table-wrapper">
        <table class="dark-table">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User Identity</th>
                    <th>Context</th>
                    <th>Violation Details</th>
                    <th>Count</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()): 
                        // Prepare the data to be passed to the modal function
                        $rowData = [
                            'alert_id' => $row['alert_id'],
                            'member_id' => $row['member_id'],
                            'full_name' => $row['full_name'],
                            'member_uid' => $row['member_uid'],
                            'member_status' => $row['member_status'],
                            'member_email' => $row['email'] ?? 'N/A',
                            'member_dept' => $row['department'] ?? 'N/A',
                            'message' => $row['message'],
                            'book_title' => $row['book_title'] ?? 'N/A',
                            'book_author' => $row['author'] ?? 'N/A',
                            'book_isbn' => $row['isbn'] ?? 'N/A',
                            'book_category' => $row['category'] ?? 'N/A',
                            'book_id' => $row['book_id'] ?? 'N/A',
                            'base_book_uid' => $row['base_book_uid'] ?? 'N/A',
                            'violations' => $row['screenshot_violations'],
                            'created_at' => date('M d, Y H:i:s', strtotime($row['created_at']))
                        ];
                        // Use ENT_QUOTES for safety when embedding JSON in a single-quoted attribute
                        $json_data = htmlspecialchars(json_encode($rowData), ENT_QUOTES, 'UTF-8');
                    ?>
                        <tr>
                            <td style="color: var(--text-muted); font-family: monospace;">
                                <?php echo date('H:i', strtotime($row['created_at'])); ?><br>
                                <small><?php echo date('M d', strtotime($row['created_at'])); ?></small>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($row['full_name']); ?></strong><br>
                                <small style="color: var(--text-muted);"><?php echo htmlspecialchars($row['member_uid']); ?></small>
                            </td>
                            <td>
                                <?php echo $row['book_title'] ? htmlspecialchars(substr($row['book_title'], 0, 20)).'...' : '<span style="opacity:0.3">N/A</span>'; ?>
                            </td>
                            <td style="color: var(--accent-danger);">
                                <i class="fas fa-ban"></i> <?php echo htmlspecialchars($row['message']); ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="background: #e2e8f0; padding: 2px 8px; borderRadius: 4px; font-weight: bold; color: var(--text-main);">
                                    <?php echo $row['screenshot_violations']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="status-indicator status-<?php echo strtolower($row['member_status']); ?>">
                                    <?php echo $row['member_status']; ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn-view-details" onclick="openDetailsModal('<?php echo $json_data; ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if ($row['member_status'] === 'Inactive'): ?>
                                    <button onclick="openUnlockModal(<?php echo $row['member_id']; ?>)" class="btn-unlock">
                                        UNLOCK
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 50px; color: var(--text-muted);">No logs found in this timeframe.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="unlockModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 400px; text-align: center;">
        <i class="fas fa-unlock-alt" style="font-size: 3rem; color: #10b981; margin-bottom: 20px;"></i>
        <h3 style="margin-top: 0; color: var(--text-main);">Reactivate User?</h3>
        <p style="color: var(--text-muted); margin-bottom: 25px;">This will grant immediate access to the library resources.</p>
        <form method="POST">
            <input type="hidden" name="reactivate_id" id="modalId">
            <button type="button" onclick="closeModal('unlockModal')">
                Cancel
            </button>
            <button type="submit" class="btn-glow" style="background: #10b981;">Confirm Unlock</button>
        </form>
    </div>
</div>

<div id="detailsModal" class="modal-overlay">
    <div class="modal-box">
        <h3 style="display: flex; align-items: center; justify-content: space-between;">
            Alert Details <span style="font-size: 0.9rem; color: var(--text-muted);">Alert ID: <span id="detailAlertId"></span></span>
        </h3>

        <div class="violation-stat">
            <div><div class="count" id="detailViolationsCount">0</div><div class="label">Screenshots Taken</div></div>
            <div style="border-left: 1px solid var(--border-color); padding-left: 20px;">
                <div class="count" id="detailPreviousViolations" style="color: #60a5fa;">Loading...</div>
                <div class="label">Previous Alerts (Total)</div>
            </div>
        </div>
        
        <div class="detail-section-title"><i class="fas fa-user-shield"></i> Reader Details</div>
        <div class="detail-grid">
            <div class="detail-item"><strong>Full Name</strong><span id="readerFullName"></span></div>
            <div class="detail-item"><strong>Member ID (UID)</strong><span id="readerMemberUid"></span></div>
            <div class="detail-item"><strong>Account Status</strong><span id="readerAccountStatus"></span></div>
            
            <div class="detail-item"><strong>Email</strong><span id="readerEmail"></span></div>
            <div class="detail-item"><strong>Department</strong><span id="readerDept"></span></div>
            <div class="detail-item"><strong>Alert Time/Date</strong><span id="readerAlertTime"></span></div>
        </div>

        <div class="detail-section-title"><i class="fas fa-book"></i> Book Details</div>
        <div class="detail-grid">
            <div class="detail-item"><strong>Title</strong><span id="bookTitle"></span></div>
            <div class="detail-item"><strong>Author</strong><span id="bookAuthor"></span></div>
            <div class="detail-item"><strong>Category</strong><span id="bookCategory"></span></div>
            
            <div class="detail-item"><strong>ISBN</strong><span id="bookISBN"></span></div>
            <div class="detail-item"><strong>Base Book ID</strong><span id="bookBaseUID"></span></div>
            <div class="detail-item"><strong>DB Book ID (Internal)</strong><span id="bookDBID"></span></div>
        </div>

        <div style="text-align: center; background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
            <strong style="color: var(--accent-danger); font-size: 0.9rem; display: block; margin-bottom: 5px;">Violation Message</strong>
            <span id="detailMessage" style="font-size: 1.1rem; font-weight: 600; color: var(--text-main);"></span>
        </div>
        
        <button type="button" onclick="closeModal('detailsModal')" 
                style="background: #e2e8f0; border: none; color: #475569; padding: 10px 20px; border-radius: 8px; cursor: pointer; margin-top: 20px; font-weight: 600;">
            Close
        </button>
    </div>
</div>

<script>
    function openUnlockModal(id) {
        document.getElementById('modalId').value = id;
        document.getElementById('unlockModal').style.display = 'flex';
    }
    
    function openDetailsModal(json_data) {
        const data = JSON.parse(json_data);

        // Populate Alert Header & Stats
        document.getElementById('detailAlertId').textContent = data.alert_id;
        document.getElementById('detailViolationsCount').textContent = data.violations;
        document.getElementById('detailMessage').textContent = data.message;
        
        // Simulate previous violation count
        document.getElementById('detailPreviousViolations').textContent = 'Fetching...';
        setTimeout(() => {
            // Placeholder logic: calculate a random previous violation count (not saved in DB currently)
            const previousViolations = Math.floor(Math.random() * 5); // Example
            document.getElementById('detailPreviousViolations').textContent = previousViolations;
        }, 300);

        // --- Reader Details ---
        document.getElementById('readerFullName').textContent = data.full_name;
        document.getElementById('readerMemberUid').textContent = data.member_uid;
        document.getElementById('readerAccountStatus').textContent = data.member_status;
        document.getElementById('readerAlertTime').textContent = data.created_at;
        document.getElementById('readerEmail').textContent = data.member_email;
        document.getElementById('readerDept').textContent = data.member_dept;


        // --- Book Details ---
        const bookTitle = data.book_title !== null ? data.book_title : 'N/A';
        const bookAuthor = data.book_author !== null ? data.book_author : 'N/A';
        const bookISBN = data.book_isbn !== null ? data.book_isbn : 'N/A';
        const bookCategory = data.book_category !== null ? data.book_category : 'N/A';
        
        const baseUid = data.base_book_uid;
        const baseUidPrefix = (baseUid && baseUid.endsWith('-BASE')) ? baseUid.replace('-BASE', '') : 'N/A';

        document.getElementById('bookTitle').textContent = bookTitle;
        document.getElementById('bookAuthor').textContent = bookAuthor;
        document.getElementById('bookISBN').textContent = bookISBN;
        document.getElementById('bookCategory').textContent = bookCategory;
        document.getElementById('bookBaseUID').textContent = baseUidPrefix;
        document.getElementById('bookDBID').textContent = data.book_id;


        document.getElementById('detailsModal').style.display = 'flex';
    }
    
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }

    // Close modal on outside click
    window.onclick = function(e) {
        if(e.target.classList.contains('modal-overlay')) {
            e.target.style.display = 'none';
        }
    }
</script>

<?php admin_footer(); close_db_connection($conn); ?>