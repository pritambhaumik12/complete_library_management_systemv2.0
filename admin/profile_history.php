<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$member_id = 0;
$member_data = null;
$raw_input = trim($_GET['member_uid'] ?? '');
$search_term = $raw_input;
$inst_init = get_setting($conn, 'institution_initials') ?: 'INS';

// --- 1. Parse Search Input ---
if (!empty($raw_input)) {
    if (preg_match('/\(([^)]+)\)$/', $raw_input, $matches)) {
        $search_term = $matches[1]; // Extract the UID part
    }
    
    // --- 2. Fetch Member Details ---
    $stmt = $conn->prepare("SELECT * FROM tbl_members WHERE member_uid = ? OR full_name = ?");
    $stmt->bind_param("ss", $search_term, $raw_input);
    $stmt->execute();
    $member_res = $stmt->get_result();
    
    if ($member_res->num_rows > 0) {
        $member_data = $member_res->fetch_assoc();
        $member_id = $member_data['member_id'];

        // B. Fetch Borrowing History
        $sql_circ = "SELECT tc.*, tb.title, tbc.book_uid, 
                     DATEDIFF(CURDATE(), tc.due_date) as overdue_days,
                     a1.full_name AS issued_by_name,
                     a2.full_name AS returned_by_name,
                     (SELECT COUNT(*) FROM tbl_reservations tr 
                      WHERE tr.book_id = tb.book_id 
                      AND tr.member_id = tc.member_id 
                      AND tr.status = 'Fulfilled') as is_reserved
                     FROM tbl_circulation tc
                     JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                     JOIN tbl_books tb ON tbc.book_id = tb.book_id
                     LEFT JOIN tbl_admin a1 ON tc.issued_by_admin_id = a1.admin_id
                     LEFT JOIN tbl_admin a2 ON tc.returned_by_admin_id = a2.admin_id
                     WHERE tc.member_id = ? ORDER BY tc.issue_date DESC";
        $stmt_circ = $conn->prepare($sql_circ);
        $stmt_circ->bind_param("i", $member_id);
        $stmt_circ->execute();
        $circulation_history = $stmt_circ->get_result();

        // C. Fetch Outstanding Fines (Unpaid History)
        $sql_fine_pending = "SELECT tf.*, tb.title, tbc.book_uid,
                             l.library_name, l.library_initials,
                             a.full_name AS created_by
                             FROM tbl_fines tf
                             LEFT JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
                             LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                             LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
                             LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                             LEFT JOIN tbl_admin a ON tc.returned_by_admin_id = a.admin_id
                             WHERE tf.member_id = ? AND tf.payment_status = 'Pending'
                             ORDER BY tf.fine_date DESC";
        $stmt_fp = $conn->prepare($sql_fine_pending);
        $stmt_fp->bind_param("i", $member_id);
        $stmt_fp->execute();
        $pending_fines = $stmt_fp->get_result();

        // D. Fetch Paid Fines History
        $sql_fine_paid = "SELECT tf.*, tb.title, tbc.book_uid,
                          l_book.library_name AS book_library,
                          l_book.library_initials AS book_lib_init,
                          a_coll.full_name AS collected_by,
                          l_coll.library_name AS collected_library,
                          a_ret.full_name AS returned_by_name
                          FROM tbl_fines tf
                          LEFT JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
                          LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                          LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
                          LEFT JOIN tbl_libraries l_book ON tb.library_id = l_book.library_id
                          LEFT JOIN tbl_admin a_coll ON tf.collected_by_admin_id = a_coll.admin_id
                          LEFT JOIN tbl_libraries l_coll ON a_coll.library_id = l_coll.library_id
                          LEFT JOIN tbl_admin a_ret ON tc.returned_by_admin_id = a_ret.admin_id
                          WHERE tf.member_id = ? AND tf.payment_status = 'Paid'
                          ORDER BY tf.paid_on DESC";
        $stmt_fpaid = $conn->prepare($sql_fine_paid);
        $stmt_fpaid->bind_param("i", $member_id);
        $stmt_fpaid->execute();
        $paid_fines = $stmt_fpaid->get_result();

        // G. Fetch Archived Fines (Cancelled History)
        $sql_fine_archived = "SELECT taf.*, tb.title, tbc.book_uid, a.full_name AS archived_by_name,
                              l.library_initials
                              FROM tbl_archived_fines taf
                              LEFT JOIN tbl_circulation tc ON taf.circulation_id = tc.circulation_id
                              LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                              LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
                              LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                              LEFT JOIN tbl_admin a ON taf.archived_by = a.admin_id
                              WHERE taf.member_id = ?
                              ORDER BY taf.archived_at DESC";
        $stmt_fa = $conn->prepare($sql_fine_archived);
        $stmt_fa->bind_param("i", $member_id);
        $stmt_fa->execute();
        $archived_fines = $stmt_fa->get_result();

        // E. Fetch Reservations (All History)
        $sql_res = "SELECT tr.*, tb.title, tb.author 
                    FROM tbl_reservations tr
                    JOIN tbl_books tb ON tr.book_id = tb.book_id
                    WHERE tr.member_id = ? ORDER BY tr.reservation_date DESC";
        $stmt_res = $conn->prepare($sql_res);
        $stmt_res->bind_param("i", $member_id);
        $stmt_res->execute();
        $reservations = $stmt_res->get_result();
        
        // F. Fetch System Alerts (Violation History)
        $sql_alerts = "SELECT ta.*, tb.title FROM tbl_system_alerts ta 
                       LEFT JOIN tbl_books tb ON ta.book_id = tb.book_id
                       WHERE ta.member_id = ? ORDER BY ta.created_at DESC";
        $stmt_alerts = $conn->prepare($sql_alerts);
        $stmt_alerts->bind_param("i", $member_id);
        $stmt_alerts->execute();
        $alerts = $stmt_alerts->get_result();
    }
}

admin_header('Profile History');
?>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<style>
    /* General Layout */
    .glass-card {
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.6);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.1);
        border-radius: 16px; padding: 25px; margin-bottom: 25px;
    }
    
    /* Search Bar Styles */
    .search-area {
        display: flex; gap: 15px; align-items: flex-end;
        max-width: 600px; margin: 0 auto 30px;
    }
    .input-group { flex: 1; position: relative; }
    .form-control {
        width: 100%; /* Changed from 90% to 100% to fill container */
        padding: 12px 40px 12px 15px; /* Added right padding for scan icon */
        border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 1rem; transition: 0.3s;
        box-sizing: border-box; /* Ensure padding doesn't affect width */
    }
    .form-control:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); outline: none; }
    
    /* Scanner Button */
    .btn-scan {
        position: absolute;
        right: 10px;
        top: 50%; /* Adjusted for label */
        transform: translateY(-20%); /* Fine-tune vertical align based on label height */
        background: transparent;
        border: none;
        color: #4f46e5;
        cursor: pointer;
        font-size: 1.2rem;
        padding: 5px;
        z-index: 10;
    }
    .btn-scan:hover { color: #3730a3; transform: translateY(-20%) scale(1.1); }

    /* Auto-complete List */
    .autocomplete-list {
        position: absolute; width: 100%; max-height: 200px; overflow-y: auto;
        background: white; border: 1px solid #d1d5db; border-top: none;
        border-radius: 0 0 10px 10px; box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        z-index: 100; left: 0; top: 100%; display: none;
    }
    .autocomplete-item { padding: 10px; cursor: pointer; border-bottom: 1px solid #f1f5f9; }
    .autocomplete-item:hover { background: #eef2ff; color: #4f46e5; }

    .btn-search {
        background: #4f46e5; color: white; border: none; padding: 12px 25px;
        border-radius: 10px; font-weight: 600; cursor: pointer;
    }
    .btn-print {
        background: #64748b; color: white; border: none; padding: 12px 25px;
        border-radius: 10px; font-weight: 600; cursor: pointer; margin-left: 10px;
    }
    .btn-print:hover { background: #475569; }

    /* Profile Header */
    .profile-header {
        display: flex; align-items: center; gap: 20px;
        border-bottom: 1px solid #e2e8f0; padding-bottom: 20px; margin-bottom: 20px;
    }
    .avatar-circle {
        width: 80px; height: 80px; background: #4f46e5; color: white;
        font-size: 2.5rem; font-weight: 700; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
    }
    .profile-info h2 { margin: 0; color: #1e293b; }
    .profile-info p { margin: 5px 0 0; color: #64748b; }
    
    .status-Active { color: #10b981; background: #d1fae5; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 700; }
    .status-Inactive { color: #ef4444; background: #fee2e2; padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 700; }

    /* Section Tables */
    .section-title {
        font-size: 1.1rem; font-weight: 700; color: #334155; margin-bottom: 15px;
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
    }
    .history-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .history-table th { background: #f8fafc; text-align: left; padding: 12px; color: #475569; text-transform: uppercase; font-size: 0.75rem; }
    .history-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; color: #1e293b; vertical-align: top; }
    .history-table tr:hover { background: #f8fafc; }

    /* In-Page Search */
    .in-page-search {
        padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: 0.9rem; width: 250px; outline: none; transition: 0.3s;
    }
    .in-page-search:focus { border-color: #4f46e5; }

    /* Badges & Small Text */
    .badge { padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 0.75rem; }
    .badge-Issued { background: #eff6ff; color: #2563eb; }
    .badge-Returned { background: #f0fdf4; color: #166534; }
    .badge-Lost { background: #fef2f2; color: #b91c1c; }
    .badge-Overdue { background: #fff7ed; color: #c2410c; }
    
    .type-direct { color: #64748b; font-weight: 600; background: #f1f5f9; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; }
    .type-reserved { color: #7c3aed; font-weight: 600; background: #f3e8ff; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; }

    .small-meta { font-size: 0.8rem; color: #64748b; margin-top: 3px; display: block; }

    /* Scanner Modal Styles */
    #scannerModal {
        display: none;
        position: fixed;
        z-index: 4000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        justify-content: center;
        align-items: center;
    }
    #scanner-container {
        background: white;
        width: 90%;
        max-width: 500px;
        border-radius: 16px;
        padding: 20px;
        position: relative;
        text-align: center;
    }
    #reader { width: 100%; margin-bottom: 15px; border-radius: 12px; overflow: hidden; }

    /* Print Specific Styles */
    @media print {
        body { background: white; font-size: 12px; }
        .sidebar, .navbar, .search-area, .in-page-search, .btn-search, .btn-print, .action-link, .btn-scan { display: none !important; }
        .glass-card { box-shadow: none; border: 1px solid #ddd; break-inside: avoid; margin-bottom: 15px; padding: 15px; }
        .history-table th { background: #eee !important; color: #000; }
        .history-table td { border-bottom: 1px solid #ddd; color: #000; }
        .profile-header { border-bottom: 1px solid #000; }
        .section-title { border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-bottom: 10px; color: #000 !important; }
        .wrapper, .content, .page-content { margin: 0; padding: 0; width: 100%; }
        a[href]:after { content: none !important; }
    }
</style>

<div class="glass-card no-print">
    <h2 style="text-align:center; margin-bottom:20px; color:#1e293b;">Member Profile History</h2>
    <form method="GET" class="search-area">
        <div class="input-group">
            <label style="display:block; margin-bottom:5px; font-weight:600; font-size:0.9rem;">Search Member</label>
            <input type="text" id="member_search" name="member_uid" class="form-control" 
                   placeholder="Enter User ID or Name..." 
                   value="<?php echo htmlspecialchars($raw_input); ?>"
                   autocomplete="off" oninput="fetchSuggestions('member', this)">
            
            <button type="button" class="btn-scan" onclick="startScanner('member_search')" title="Scan ID Card">
                <i class="fas fa-qrcode"></i>
            </button>

            <div id="member_search_suggestions" class="autocomplete-list"></div>
        </div>
        <button type="submit" class="btn-search"><i class="fas fa-search"></i> View Profile</button>
        <?php if ($member_data): ?>
            <button type="button" class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
        <?php endif; ?>
    </form>
</div>

<?php if ($member_data): ?>

    <div class="glass-card">
        <div class="profile-header">
            <div class="avatar-circle">
                <?php echo strtoupper(substr($member_data['full_name'], 0, 1)); ?>
            </div>
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($member_data['full_name']); ?></h2>
                <p>
                    ID: <strong><?php echo htmlspecialchars($member_data['member_uid']); ?></strong> &bull; 
                    Dept: <?php echo htmlspecialchars($member_data['department']); ?> &bull;
                    Status: <span class="status-<?php echo $member_data['status']; ?>"><?php echo $member_data['status']; ?></span>
                </p>
                <p><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($member_data['email'] ?? 'N/A'); ?></p>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 15px; margin-top: 20px;">
            <div style="background:#f1f5f9; padding:15px; border-radius:10px; text-align:center;">
                <h3 style="margin:0; color:#4f46e5;"><?php echo $circulation_history->num_rows; ?></h3>
                <small style="color:#64748b; font-weight:600;">Total Loans</small>
            </div>
            <div style="background:#f1f5f9; padding:15px; border-radius:10px; text-align:center;">
                <h3 style="margin:0; color:#ef4444;"><?php echo $pending_fines->num_rows; ?></h3>
                <small style="color:#64748b; font-weight:600;">Unpaid Fines</small>
            </div>
            <div style="background:#f1f5f9; padding:15px; border-radius:10px; text-align:center;">
                <h3 style="margin:0; color:#10b981;"><?php echo $reservations->num_rows; ?></h3>
                <small style="color:#64748b; font-weight:600;">Reservations</small>
            </div>
            <div style="background:#f1f5f9; padding:15px; border-radius:10px; text-align:center;">
                <h3 style="margin:0; color:#f59e0b;"><?php echo $member_data['screenshot_violations']; ?></h3>
                <small style="color:#64748b; font-weight:600;">Violations</small>
            </div>
            <div style="background:#f1f5f9; padding:15px; border-radius:10px; text-align:center;">
                <h3 style="margin:0; color:#64748b;"><?php echo $archived_fines->num_rows; ?></h3>
                <small style="color:#64748b; font-weight:600;">Cancelled Fines</small>
            </div>
        </div>
    </div>

    <?php if($pending_fines->num_rows > 0): ?>
    <div class="glass-card" style="border-left: 5px solid #ef4444;">
        <div class="section-title" style="color:#ef4444;">
            <span><i class="fas fa-exclamation-circle"></i> Outstanding Fines (Dues)</span>
        </div>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Fine ID</th>
                    <th>Book</th>
                    <th>Reason</th>
                    <th>Creation Details</th>
                    <th>Date</th>
                    <th>Amount</th>
                    <th class="action-link">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $pending_fines->fetch_assoc()): 
                    $libInit = $row['library_initials'] ?: 'LIB';
                    $formatted_id = strtoupper($inst_init . '/' . $libInit . '/FINE/' . $row['fine_id']);
                    $creator = $row['created_by'] ?? 'System/Manual';
                    $library = $row['library_name'] ?? 'N/A';
                ?>
                <tr>
                    <td><strong style="font-family:monospace; color:#ef4444; font-size:0.85rem;"><?php echo $formatted_id; ?></strong></td>
                    <td>
                        <?php echo htmlspecialchars($row['title'] ?? 'Manual Fine'); ?>
                        <br><span class="small-meta"><?php echo htmlspecialchars($row['book_uid'] ?? ''); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($row['fine_type']); ?></td>
                    <td>
                        <span class="small-meta">By: <strong><?php echo htmlspecialchars($creator); ?></strong></span>
                        <span class="small-meta">In: <?php echo htmlspecialchars($library); ?></span>
                    </td>
                    <td><?php echo date('d M Y', strtotime($row['fine_date'])); ?></td>
                    <td style="font-weight:bold; color:#ef4444;"><?php echo get_setting($conn, 'currency_symbol') . $row['fine_amount']; ?></td>
                    <td class="action-link"><a href="fines.php" target="_blank" style="color:#4f46e5; font-weight:600;">Collect</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="glass-card">
        <div class="section-title">
            <span><i class="fas fa-calendar-alt"></i> Reservation History</span>
            <input type="text" class="in-page-search" placeholder="Filter reservations..." onkeyup="filterTable(this, 'resTable')">
        </div>
        <?php if($reservations->num_rows > 0): ?>
        <table class="history-table" id="resTable">
            <thead>
                <tr>
                    <th>Res ID</th>
                    <th>Book Requested</th>
                    <th>Date Requested</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $reservations->fetch_assoc()): ?>
                <tr>
                    <td><strong style="font-family: monospace; color: #4f46e5;"><?php echo htmlspecialchars($row['reservation_uid']); ?></strong></td>
                    <td>
                        <strong><?php echo htmlspecialchars($row['title']); ?></strong><br>
                        <span class="small-meta">By: <?php echo htmlspecialchars($row['author']); ?></span>
                    </td>
                    <td><?php echo date('d M Y', strtotime($row['reservation_date'])); ?></td>
                    <td>
                        <span class="badge" style="background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0;"><?php echo htmlspecialchars($row['status']); ?></span>
                        <?php if(!empty($row['cancelled_by'])): ?>
                            <span class="small-meta" style="color: #ef4444;">Cancelled by: <?php echo htmlspecialchars($row['cancelled_by']); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:#64748b; text-align:center;">No reservation history found.</p>
        <?php endif; ?>
    </div>

    <div class="glass-card">
        <div class="section-title">
            <span><i class="fas fa-history"></i> Circulation History</span>
            <input type="text" class="in-page-search" placeholder="Filter history..." onkeyup="filterTable(this, 'circTable')">
        </div>
        <?php if($circulation_history->num_rows > 0): ?>
        <table class="history-table" id="circTable">
            <thead>
                <tr>
                    <th>Book Info</th>
                    <th>Issue Info</th>
                    <th>Return Info</th>
                    <th>Issue Type</th> <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $circulation_history->fetch_assoc()): 
                    $statusClass = 'badge-' . $row['status'];
                    if($row['status'] == 'Issued' && $row['overdue_days'] > 0) {
                        $statusClass = 'badge-Overdue';
                        $row['status'] = 'Overdue (' . $row['overdue_days'] . 'd)';
                    }
                    
                    // Determine "Issue Type" as Origin (Direct vs Reserved)
                    $origin = ($row['is_reserved'] > 0) ? "Reserved" : "Direct";
                    $originClass = ($row['is_reserved'] > 0) ? "type-reserved" : "type-direct";
                ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($row['title']); ?></strong><br>
                        <span class="small-meta">ID: <?php echo htmlspecialchars($row['book_uid']); ?></span>
                    </td>
                    <td>
                        <i class="fas fa-calendar-check"></i> <?php echo date('d M Y', strtotime($row['issue_date'])); ?><br>
                        <span class="small-meta">By: <?php echo htmlspecialchars($row['issued_by_name'] ?? 'System'); ?></span>
                    </td>
                    <td>
                        <?php if($row['return_date']): ?>
                            <i class="fas fa-undo"></i> <?php echo date('d M Y', strtotime($row['return_date'])); ?><br>
                            <span class="small-meta">By: <?php echo htmlspecialchars($row['returned_by_name'] ?? 'System'); ?></span>
                        <?php else: ?>
                            <span style="color:#9ca3af;">Due: <?php echo date('d M Y', strtotime($row['due_date'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="<?php echo $originClass; ?>"><?php echo $origin; ?></span></td>
                    <td><span class="badge <?php echo $statusClass; ?>"><?php echo $row['status']; ?></span></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:#64748b; text-align:center;">No borrowing history found.</p>
        <?php endif; ?>
    </div>

    <div class="glass-card" style="border:1px solid #fcd34d;">
        <div class="section-title" style="color:#d97706;">
            <span><i class="fas fa-shield-alt"></i> Violation History (Security Alerts)</span>
        </div>
        <?php if($alerts->num_rows > 0): ?>
        <table class="history-table">
            <thead><tr><th>Date</th><th>Book Context</th><th>Message</th></tr></thead>
            <tbody>
                <?php while($row = $alerts->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('d M Y H:i', strtotime($row['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($row['title'] ?? 'General'); ?></td>
                    <td style="color:#d97706; font-weight: 600;"><?php echo htmlspecialchars($row['message']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:#64748b; text-align:center; padding: 20px;">No security violations recorded for this user.</p>
        <?php endif; ?>
    </div>

    <div class="glass-card">
        <div class="section-title">
            <span><i class="fas fa-receipt"></i> Paid Fines & Receipts</span>
            <input type="text" class="in-page-search" placeholder="Filter receipts..." onkeyup="filterTable(this, 'fineTable')">
        </div>
        <?php if($paid_fines->num_rows > 0): ?>
        <table class="history-table" id="fineTable">
            <thead>
                <tr>
                    <th>Receipt ID</th>
                    <th>Creation Details</th>
                    <th>Fine Reason</th>
                    <th>Amount</th>
                    <th>Collection Details</th>
                    <th class="action-link">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $paid_fines->fetch_assoc()): 
                    // Receipt ID Format: {INST}/{LIB}/FINE/{ID}
                    $libInitials = $row['book_lib_init'] ?? 'LIB';
                    $receiptId = strtoupper($inst_init . '/' . $libInitials . '/FINE/' . $row['fine_id']);
                    
                    // Determine Creator (Proxy)
                    $createdBy = $row['returned_by_name'] ?? 'System/Admin';
                    $createdLib = $row['book_library'] ?? 'General Library';
                ?>
                <tr>
                    <td>
                        <strong style="font-family:monospace; color:#4f46e5;"><?php echo $receiptId; ?></strong><br>
                        <span class="small-meta">Date: <?php echo date('d M Y', strtotime($row['paid_on'])); ?></span>
                    </td>
                    <td>
                        <span class="small-meta">By: <strong><?php echo htmlspecialchars($createdBy); ?></strong></span>
                        <span class="small-meta">In: <?php echo htmlspecialchars($createdLib); ?></span>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($row['fine_type']); ?><br>
                        <span class="small-meta">Book: <?php echo htmlspecialchars($row['title'] ?? 'N/A'); ?></span>
                    </td>
                    <td style="font-weight:bold; color:#10b981;">
                        <?php echo get_setting($conn, 'currency_symbol') . $row['fine_amount']; ?>
                    </td>
                    <td>
                        <span class="small-meta">Recv By: <strong><?php echo htmlspecialchars($row['collected_by'] ?? 'System'); ?></strong></span>
                        <span class="small-meta">In: <?php echo htmlspecialchars($row['collected_library'] ?? 'N/A'); ?></span>
                    </td>
                    <td class="action-link"><a href="generate_receipt.php?fine_id=<?php echo $row['fine_id']; ?>" target="_blank" class="print-btn"><i class="fas fa-print"></i> Print</a></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:#64748b; text-align:center;">No payment history found.</p>
        <?php endif; ?>
    </div>

    <div class="glass-card" style="border-top: 4px solid #64748b;">
        <div class="section-title" style="color:#64748b;">
            <span><i class="fas fa-archive"></i> Archived / Cancelled Fines</span>
            <input type="text" class="in-page-search" placeholder="Filter archive..." onkeyup="filterTable(this, 'archiveTable')">
        </div>
        <?php if($archived_fines->num_rows > 0): ?>
        <table class="history-table" id="archiveTable">
            <thead>
                <tr>
                    <th>Fine ID</th>
                    <th>Book</th>
                    <th>Reason</th>
                    <th>Amount</th>
                    <th>Archived By</th>
                    <th>Archive Reason</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php while($row = $archived_fines->fetch_assoc()): 
                    $libInit = $row['library_initials'] ?: 'LIB';
                    $formatted_fine_id = strtoupper($inst_init . '/' . $libInit . '/FINE/' . $row['fine_id']);
                ?>
                <tr>
                    <td>
                        <strong style="font-family:monospace; color:#64748b;"><?php echo $formatted_fine_id; ?></strong>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($row['title'] ?? 'N/A'); ?><br>
                        <span class="small-meta"><?php echo htmlspecialchars($row['book_uid'] ?? ''); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($row['fine_type']); ?></td>
                    <td style="font-weight:bold; color:#64748b;"><?php echo get_setting($conn, 'currency_symbol') . $row['fine_amount']; ?></td>
                    <td><?php echo htmlspecialchars($row['archived_by_name'] ?? 'System'); ?></td>
                    <td style="color:#ef4444; font-style:italic;"><?php echo htmlspecialchars($row['archive_reason']); ?></td>
                    <td><?php echo date('d M Y H:i', strtotime($row['archived_at'])); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:#64748b; text-align:center;">No archived fines found.</p>
        <?php endif; ?>
    </div>

<?php elseif (!empty($search_query)): ?>
    <div class="glass-card" style="text-align:center; padding:40px;">
        <i class="fas fa-user-slash" style="font-size:3rem; color:#cbd5e1; margin-bottom:15px;"></i>
        <h3 style="color:#64748b;">Member Not Found</h3>
        <p style="color:#94a3b8;">Could not find a member with ID or Name: "<?php echo htmlspecialchars($search_query); ?>"</p>
    </div>
<?php endif; ?>

<div id="scannerModal">
    <div id="scanner-container">
        <h3 style="text-align: center; margin-top: 0;">Scan Code</h3>
        <div id="reader"></div>
        <button onclick="stopScanner()" class="btn-search" style="background: #ef4444; margin-top: 15px; width: auto; display: inline-block; padding: 10px 20px;">Close Scanner</button>
    </div>
</div>

<script>
    function fetchSuggestions(uidType, inputElement) {
        const query = inputElement.value;
        const suggestionBox = document.getElementById('member_search_suggestions');
        
        if (query.length < 3) {
            suggestionBox.style.display = 'none';
            return;
        }

        fetch('fetch_uids.php?uid_type=' + uidType + '&query=' + query)
            .then(response => response.json())
            .then(data => {
                suggestionBox.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(item => {
                        const itemElement = document.createElement('div');
                        itemElement.className = 'autocomplete-item';
                        itemElement.textContent = item; 
                        itemElement.onclick = function() {
                            inputElement.value = item;
                            suggestionBox.style.display = 'none';
                        };
                        suggestionBox.appendChild(itemElement);
                    });
                    suggestionBox.style.display = 'block';
                } else {
                    suggestionBox.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                suggestionBox.style.display = 'none';
            });
    }

    // Close suggestions on click outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.input-group')) {
            document.getElementById('member_search_suggestions').style.display = 'none';
        }
    });

    // Client-side Filter for History Tables
    function filterTable(input, tableId) {
        const filter = input.value.toUpperCase();
        const table = document.getElementById(tableId);
        const tr = table.getElementsByTagName("tr");

        for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header
            let rowVisible = false;
            const tds = tr[i].getElementsByTagName("td");
            for (let j = 0; j < tds.length; j++) {
                if (tds[j]) {
                    const txtValue = tds[j].textContent || tds[j].innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        rowVisible = true;
                        break;
                    }
                }
            }
            tr[i].style.display = rowVisible ? "" : "none";
        }
    }

    // --- SCANNER LOGIC ---
    let html5QrcodeScanner = null;
    let currentInputId = null;

    function startScanner(inputId) {
        currentInputId = inputId;
        document.getElementById('scannerModal').style.display = 'flex';

        // Initialize Scanner if not already created
        if (html5QrcodeScanner === null) {
            html5QrcodeScanner = new Html5QrcodeScanner(
                "reader", 
                { fps: 10, qrbox: {width: 250, height: 250} },
                /* verbose= */ false
            );
            
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }
    }

    function onScanSuccess(decodedText, decodedResult) {
        // Handle the scanned code
        if (currentInputId) {
            const inputField = document.getElementById(currentInputId);
            inputField.value = decodedText;
            
            // Close scanner automatically on success
            stopScanner();
        }
    }

    function onScanFailure(error) {
        // Handle scan failure (optional)
        // console.warn(`Code scan error = ${error}`);
    }

    function stopScanner() {
        document.getElementById('scannerModal').style.display = 'none';
        
        // Clear the scanner instance to stop the camera
        if(html5QrcodeScanner) {
            html5QrcodeScanner.clear().then(() => {
                html5QrcodeScanner = null; // Reset to allow re-initialization next time
            }).catch(error => {
                console.error("Failed to clear scanner", error);
            });
        }
    }
</script>

<?php admin_footer(); close_db_connection($conn); ?>