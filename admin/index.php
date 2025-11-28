<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

// --- 1. ALERT LOGIC (Existing) ---
if (!isset($_SESSION['last_alert_view_timestamp'])) {
    $_SESSION['last_alert_view_timestamp'] = 0; 
}
$result_latest = $conn->query("SELECT UNIX_TIMESTAMP(created_at) AS latest_timestamp FROM tbl_system_alerts ORDER BY created_at DESC LIMIT 1");
$latest_alert_time = (int)($result_latest->fetch_assoc()['latest_timestamp'] ?? 0);
$show_initial_alert = $latest_alert_time > $_SESSION['last_alert_view_timestamp'];

// --- 2. DETERMINE ADMIN ROLE & SCOPE ---
$is_super = is_super_admin($conn);
$admin_id = $_SESSION['admin_id'];
$assigned_library_id = 0;
$assigned_library_name = ""; // Store the name
$library_filter_sql = ""; 
$params = [];
$types = "";

// Handle Library Selection for Super Admin (via GET parameter)
$selected_lib_id = isset($_GET['lib_filter']) ? (int)$_GET['lib_filter'] : 0;

// FETCH LIBRARIES FOR DROPDOWN (Super Admin Only)
$all_libraries = [];
if ($is_super) {
    $lib_res = $conn->query("SELECT library_id, library_name FROM tbl_libraries ORDER BY library_name ASC");
    while($row = $lib_res->fetch_assoc()) {
        $all_libraries[] = $row;
    }

    // Apply filter if a specific library is selected from dropdown
    if ($selected_lib_id > 0) {
        $library_filter_sql = " AND b.library_id = ? ";
        $params[] = $selected_lib_id;
        $types = "i";
    }
} else {
    // Regular Admin: Fetch assigned library
    $stmt_admin = $conn->prepare("SELECT a.library_id, l.library_name FROM tbl_admin a LEFT JOIN tbl_libraries l ON a.library_id = l.library_id WHERE a.admin_id = ?");
    $stmt_admin->bind_param("i", $admin_id);
    $stmt_admin->execute();
    $res_admin = $stmt_admin->get_result();
    if ($row = $res_admin->fetch_assoc()) {
        $assigned_library_id = (int)$row['library_id'];
        $assigned_library_name = $row['library_name'] ?? 'Unknown Library';
    }
    
    // If assigned to a specific library, apply filter
    if ($assigned_library_id > 0) {
        $library_filter_sql = " AND b.library_id = ? ";
        $params[] = $assigned_library_id;
        $types = "i";
    }
}

// --- 3. FETCH CONTEXT-AWARE STATISTICS ---

$stats = [
    'total_titles' => 0,
    'total_copies' => 0,
    'total_members' => 0,
    'total_issued' => 0,
    'total_overdue' => 0,
    'pending_reservations' => 0,
    'accepted_reservations' => 0
];

// Helper to execute prepared statement with optional filter
function get_stat($conn, $sql, $types, $params) {
    $stmt = $conn->prepare($sql);
    if (!empty($types) && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc()['count'] ?? 0;
}

// A. Total Books (Unique Titles) & Total Copies (Volumes)
$sql_titles = "SELECT COUNT(*) AS count FROM tbl_books b WHERE 1=1 " . $library_filter_sql;
$stats['total_titles'] = get_stat($conn, $sql_titles, $types, $params);

$sql_copies = "SELECT SUM(total_quantity) AS count FROM tbl_books b WHERE 1=1 " . $library_filter_sql;
$stats['total_copies'] = get_stat($conn, $sql_copies, $types, $params);

// B. Circulation Stats
$sql_issued = "
    SELECT COUNT(*) AS count 
    FROM tbl_circulation tc
    JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN tbl_books b ON tbc.book_id = b.book_id
    WHERE tc.status = 'Issued' $library_filter_sql
";
$stats['total_issued'] = get_stat($conn, $sql_issued, $types, $params);

$sql_overdue = "
    SELECT COUNT(*) AS count 
    FROM tbl_circulation tc
    JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN tbl_books b ON tbc.book_id = b.book_id
    WHERE tc.status = 'Issued' AND tc.due_date < CURDATE() $library_filter_sql
";
$stats['total_overdue'] = get_stat($conn, $sql_overdue, $types, $params);

// C. Reservation Stats
$sql_pending_res = "
    SELECT COUNT(*) AS count 
    FROM tbl_reservations tr
    JOIN tbl_books b ON tr.book_id = b.book_id
    WHERE tr.status = 'Pending' $library_filter_sql
";
$stats['pending_reservations'] = get_stat($conn, $sql_pending_res, $types, $params);

$sql_accepted_res = "
    SELECT COUNT(*) AS count 
    FROM tbl_reservations tr
    JOIN tbl_books b ON tr.book_id = b.book_id
    WHERE tr.status = 'Accepted' $library_filter_sql
";
$stats['accepted_reservations'] = get_stat($conn, $sql_accepted_res, $types, $params);

// D. Total Active Members (Global)
$result = $conn->query("SELECT COUNT(*) AS count FROM tbl_members WHERE status = 'Active'");
$stats['total_members'] = $result->fetch_assoc()['count'] ?? 0;


admin_header('Admin Dashboard');
?>

<style>
    /* --- DASHBOARD STYLES --- */
    .welcome-section {
        margin-bottom: 40px;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        flex-wrap: wrap;
        gap: 20px;
    }

    .welcome-text-group { display: flex; flex-direction: column; gap: 10px; }

    .welcome-text h2 {
        font-size: 2.2rem; font-weight: 800; color: #ffffff;
        text-shadow: 0 2px 10px rgba(0,0,0,0.3); margin: 0;
    }

    .welcome-text p { color: #cbd5e1; font-size: 1.1rem; margin-top: 5px; font-weight: 500; }
    
    /* Alert Pill */
    @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }
    .security-alert-pill {
        display: flex; align-items: center; gap: 8px;
        background: #ef4444; color: white; padding: 5px 12px;
        border-radius: 8px; font-weight: 700; font-size: 0.9rem;
        animation: blink 1.5s step-end infinite;
        box-shadow: 0 0 10px rgba(239, 68, 68, 0.7); width: fit-content;
    }
    
    /* Library Selector Style */
    .lib-selector {
        padding: 8px 12px; border-radius: 8px; border: none;
        background: rgba(255,255,255,0.9); color: #1e293b; font-weight: 600;
        cursor: pointer; outline: none; font-size: 0.9rem;
    }

    /* Date Badge */
    .date-badge {
        background: rgba(0, 0, 0, 0.4); backdrop-filter: blur(12px);
        border: 1px solid rgba(255, 255, 255, 0.2); padding: 12px 25px;
        border-radius: 16px; text-align: right; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        min-width: 200px;
    }
    
    .date-display {
        font-size: 0.95rem; color: #e2e8f0; margin-bottom: 4px;
        border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 4px; display: block;
    }

    .live-time {
        font-family: 'Courier New', monospace; font-weight: 700;
        font-size: 1.3rem; color: #67e8f9; letter-spacing: 1px;
    }

    /* Stats Grid */
    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 25px;
        margin-bottom: 50px;
    }

    .stat-card {
        text-decoration: none; transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative; overflow: hidden;
        background: rgba(255, 255, 255, 0.85) !important;
        border-radius: 20px; padding: 25px;
        border: 1px solid rgba(255,255,255,0.5);
        display: flex; flex-direction: column; justify-content: space-between;
        min-height: 140px;
    }

    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2); }

    .stat-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }

    .icon-circle {
        width: 50px; height: 50px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.2rem; box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }

    .stat-value {
        font-size: 2.5rem; font-weight: 800; margin: 5px 0 0 0;
        color: #1e293b; line-height: 1;
    }

    .stat-label {
        font-size: 0.85rem; color: #64748b; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.5px;
    }

    .stat-footer {
        margin-top: auto; font-size: 0.85rem; display: flex;
        align-items: center; gap: 5px; font-weight: 600;
    }

    /* Colors */
    .card-blue .icon-circle { background: #eff6ff; color: #3b82f6; }
    .card-blue .stat-footer { color: #3b82f6; }
    
    .card-green .icon-circle { background: #ecfdf5; color: #10b981; }
    .card-green .stat-footer { color: #10b981; }
    
    .card-purple .icon-circle { background: #f5f3ff; color: #8b5cf6; }
    .card-purple .stat-footer { color: #8b5cf6; }
    
    .card-red .icon-circle { background: #fef2f2; color: #ef4444; }
    .card-red .stat-footer { color: #ef4444; }
    
    .card-orange .icon-circle { background: #fff7ed; color: #f97316; }
    .card-orange .stat-footer { color: #f97316; }

    .card-cyan .icon-circle { background: #ecfeff; color: #06b6d4; }
    .card-cyan .stat-footer { color: #06b6d4; }
    
    .card-indigo .icon-circle { background: #e0e7ff; color: #4338ca; }
    .card-indigo .stat-footer { color: #4338ca; }

    /* Quick Actions */
    .section-header { display: flex; align-items: center; gap: 12px; margin-bottom: 25px; }
    .section-header h3 { font-size: 1.4rem; margin: 0; color: #ffffff; text-shadow: 0 2px 4px rgba(0,0,0,0.3); }

    .actions-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 20px; }

    .action-btn {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 12px; text-decoration: none; color: #334155; transition: 0.2s;
        text-align: center; background: rgba(255, 255, 255, 0.9);
        padding: 20px; border-radius: 16px;
    }
    .action-btn i {
        font-size: 1.5rem; color: #6366f1; background: rgba(99, 102, 241, 0.1);
        width: 60px; height: 60px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; transition: 0.3s;
    }
    .action-btn:hover { transform: translateY(-5px); }
    .action-btn:hover i { background: #6366f1; color: white; box-shadow: 0 5px 15px rgba(99, 102, 241, 0.4); }
    .action-btn span { font-weight: 700; font-size: 0.95rem; }

    @media (max-width: 600px) {
        .welcome-section { flex-direction: column; align-items: flex-start; }
        .date-badge { text-align: left; width: 100%; }
        .security-alert-pill { margin-top: 10px; }
    }
</style>

<div class="welcome-section">
    <div class="welcome-text-group">
        <div class="welcome-text">
            <h2>Dashboard Overview</h2>
            <p>Welcome back, <strong><?php echo htmlspecialchars($_SESSION['admin_full_name'] ?? 'Admin'); ?></strong>.</p>
            
            <?php if(!$is_super && $assigned_library_id > 0): ?>
                <span style="background: rgba(255,255,255,0.2); padding: 6px 12px; border-radius: 8px; font-size: 0.9rem; color: #fff; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-building"></i> You are managing: <strong><?php echo htmlspecialchars($assigned_library_name); ?></strong>
                </span>
            <?php elseif($is_super): ?>
                <form method="GET" style="display: inline-block;">
                    <span style="background: rgba(255,255,255,0.2); padding: 6px 12px; border-radius: 8px; font-size: 0.9rem; color: #fff; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-globe"></i> You are managing: <strong>Global Data</strong>
                        &nbsp;|&nbsp; View Library: 
                        <select name="lib_filter" class="lib-selector" onchange="this.form.submit()">
                            <option value="0">All Libraries</option>
                            <?php foreach($all_libraries as $lib): ?>
                                <option value="<?php echo $lib['library_id']; ?>" <?php echo ($selected_lib_id == $lib['library_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($lib['library_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                </form>
            <?php endif; ?>
        </div>
        
        <div id="securityAlertContainer" style="display: <?php echo $show_initial_alert ? 'block' : 'none'; ?>;">
            <a href="system_alerts.php?tab=present" id="alertPill" class="security-alert-pill">
                <i class="fas fa-shield-alt"></i> NEW SECURITY ALERT
            </a>
        </div>
    </div>
    
    <div class="date-badge">
        <span class="date-display">
            <i class="far fa-calendar-alt"></i> <?php echo date("l, F j, Y"); ?>
        </span>
        <div class="live-time" id="live-clock">00:00:00 AM</div>
    </div>
</div>

<div class="dashboard-grid">
    <a href="books.php" class="stat-card card-blue">
        <div class="stat-header">
            <div>
                <div class="stat-label">Total Books</div>
                <div class="stat-value"><?php echo number_format($stats['total_titles']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-book"></i></div>
        </div>
        <div class="stat-footer">Manage Catalog <i class="fas fa-arrow-right"></i></div>
    </a>

    <a href="book_copies.php" class="stat-card card-indigo">
        <div class="stat-header">
            <div>
                <div class="stat-label">Total Copies</div>
                <div class="stat-value"><?php echo number_format($stats['total_copies']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-layer-group"></i></div>
        </div>
        <div class="stat-footer">View Copies <i class="fas fa-arrow-right"></i></div>
    </a>

    <a href="issue_book.php" class="stat-card card-purple">
        <div class="stat-header">
            <div>
                <div class="stat-label">Currently Issued</div>
                <div class="stat-value"><?php echo number_format($stats['total_issued']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-file-signature"></i></div>
        </div>
        <div class="stat-footer">View Circulation <i class="fas fa-arrow-right"></i></div>
    </a>
    
    <a href="reports.php" class="stat-card card-red">
        <div class="stat-header">
            <div>
                <div class="stat-label">Overdue Books</div>
                <div class="stat-value"><?php echo number_format($stats['total_overdue']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-exclamation-circle"></i></div>
        </div>
        <div class="stat-footer">View Reports <i class="fas fa-arrow-right"></i></div>
    </a>

    <a href="reservations.php?status=Pending" class="stat-card card-orange">
        <div class="stat-header">
            <div>
                <div class="stat-label">Pending Requests</div>
                <div class="stat-value"><?php echo number_format($stats['pending_reservations']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-clock"></i></div>
        </div>
        <div class="stat-footer">Process Requests <i class="fas fa-arrow-right"></i></div>
    </a>

    <a href="reservations.php?status=Accepted" class="stat-card card-cyan">
        <div class="stat-header">
            <div>
                <div class="stat-label">Accepted / Active</div>
                <div class="stat-value"><?php echo number_format($stats['accepted_reservations']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-check-double"></i></div>
        </div>
        <div class="stat-footer">Ready to Issue <i class="fas fa-arrow-right"></i></div>
    </a>

    <a href="members.php" class="stat-card card-green">
        <div class="stat-header">
            <div>
                <div class="stat-label">Active Members</div>
                <div class="stat-value"><?php echo number_format($stats['total_members']); ?></div>
            </div>
            <div class="icon-circle"><i class="fas fa-users"></i></div>
        </div>
        <div class="stat-footer">Manage Users <i class="fas fa-arrow-right"></i></div>
    </a>
</div>

<div class="section-header">
    <div style="background: rgba(255, 255, 255, 0.15); padding: 10px; border-radius: 12px; color: #fbbf24; border: 1px solid rgba(255,255,255,0.2);">
        <i class="fas fa-bolt"></i>
    </div>
    <h3>Quick Actions</h3>
</div>

<div class="actions-grid">
    <a href="issue_book.php" class="action-btn">
        <i class="fas fa-file-signature"></i>
        <span>Issue Book</span>
    </a>
    
    <a href="return_book.php" class="action-btn">
        <i class="fas fa-undo-alt"></i>
        <span>Return Book</span>
    </a>
    
    <a href="books.php?action=add" class="action-btn">
        <i class="fas fa-plus"></i>
        <span>Add Book</span>
    </a>
    
    <a href="members.php?action=add" class="action-btn">
        <i class="fas fa-user-plus"></i>
        <span>Add Member</span>
    </a>

    <a href="reservations.php" class="action-btn">
        <i class="fas fa-calendar-check"></i>
        <span>Reservations</span>
    </a>
</div>

<script>
    function updateClock() {
        const now = new Date();
        let hours = now.getHours();
        const ampm = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12;
        hours = hours ? hours : 12; 
        const minutes = now.getMinutes().toString().padStart(2, '0');
        const seconds = now.getSeconds().toString().padStart(2, '0');
        const hours24 = now.getHours().toString().padStart(2, '0');
        
        const timeStr = `${hours}:${minutes}:${seconds} ${ampm}`;
        
        document.getElementById('live-clock').innerHTML = `${timeStr} <span style="font-size:0.75em; opacity:0.8; color: #cbd5e1;">(${hours24}:${minutes})</span>`;
    }

    let lastAcknowledgedTime = <?php echo $_SESSION['last_alert_view_timestamp']; ?>;
    
    function acknowledgeAlert() {
        fetch('acknowledge_alert.php') 
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    lastAcknowledgedTime = data.timestamp;
                    document.getElementById('securityAlertContainer').style.display = 'none';
                }
            })
            .catch(error => console.error('Error acknowledging alert:', error));
    }

    function checkSecurityAlerts() {
        fetch('fetch_alerts.php')
            .then(response => response.json())
            .then(data => {
                if (data.latest_timestamp > lastAcknowledgedTime) {
                    document.getElementById('securityAlertContainer').style.display = 'block';
                }
            })
            .catch(error => console.error('Error fetching alerts:', error));
    }
    
    document.getElementById('alertPill').addEventListener('click', function(e) {
        e.preventDefault(); 
        acknowledgeAlert();
        setTimeout(() => { window.location.href = this.href; }, 100); 
    });

    setInterval(checkSecurityAlerts, 300000); // 5 minutes
    
    setInterval(updateClock, 1000);
    updateClock();
</script>

<?php
admin_footer();
close_db_connection($conn);
?>