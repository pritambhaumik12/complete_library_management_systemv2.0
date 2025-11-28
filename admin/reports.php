<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

// Helper function placeholder (assuming it exists in functions.php)
if (!function_exists('get_setting')) {
    function get_setting($conn, $key) {
        $stmt = $conn->prepare("SELECT setting_value FROM tbl_settings WHERE setting_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['setting_value'] ?? '';
    }
}

// --- 1. DETERMINE ADMIN SCOPE ---
$is_super = is_super_admin($conn);
$admin_id = $_SESSION['admin_id'];
$library_filter_sql = "";
$my_lib_id = 0;

if (!$is_super) {
    // Fetch Admin's Assigned Library
    $stmt_lib = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
    $stmt_lib->bind_param("i", $admin_id);
    $stmt_lib->execute();
    $my_lib_id = $stmt_lib->get_result()->fetch_assoc()['library_id'] ?? 0;

    // Only apply filter if a library is actually assigned
    if ($my_lib_id > 0) {
        // This SQL snippet can be appended to queries that alias tbl_books as 'tb'
        $library_filter_sql = " AND tb.library_id = $my_lib_id ";
    }
}

// Common Variables
$currency = get_setting($conn, 'currency_symbol');
$inst_init = get_setting($conn, 'institution_initials') ?: 'INS';

admin_header('System Reports');
?>

<style>
    /* * Glass Components - White Transparent & Glass Effect 
     * Primary Text - Deep Black/Dark Gray 
     */
    body { background-color: #f0f4f8; } 
    
    .glass-card {
        background: rgba(255, 255, 255, 0.8); 
        backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.7);
        box-shadow: 0 4px 15px 0 rgba(0, 0, 0, 0.1);
        border-radius: 18px; padding: 30px; margin-bottom: 30px;
        animation: fadeIn 0.5s ease-out;
    }

    .card-header {
        display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;
        border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 15px; 
    }
    .card-header h2 { margin: 0; color: #111827; font-size: 1.6rem; display: flex; align-items: center; gap: 10px; }

    /* Search & Filter Forms */
    .search-container { display: flex; gap: 10px; }
    .filter-form { display: flex; align-items: center; gap: 15px; background: rgba(249,250,251,0.8); padding: 15px; border-radius: 12px; flex-wrap: wrap; }
    
    .form-control {
        padding: 10px 15px; border: 1px solid rgba(0,0,0,0.2); 
        background: rgba(255,255,255,0.9);
        border-radius: 10px; width: auto; transition: 0.3s; color: #111827; 
    }
    .form-control:focus { background: #fff; border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }

    /* Buttons */
    .btn-main {
        background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 10px; cursor: pointer; font-weight: 600; transition: 0.2s;
    }
    .btn-main:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(59, 130, 246, 0.4); }
    .btn-filter { background: #4b5563; } 
    .btn-filter:hover { background: #374151; }

    /* Table */
    .table-container { overflow-x: auto; border-radius: 12px; }
    .glass-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .glass-table th { background: rgba(59, 130, 246, 0.1); color: #111827; font-weight: 700; padding: 15px; text-align: left; font-size: 0.85rem; text-transform: uppercase; }
    .glass-table td { padding: 15px; border-bottom: 1px solid rgba(0,0,0,0.08); color: #1f2937; font-size: 0.95rem; vertical-align: middle; }
    .glass-table tr:hover { background: rgba(0, 0, 0, 0.03); }
    
    /* Special Rows */
    .row-danger { background: rgba(254, 226, 226, 0.6); } 
    .row-danger td { color: #b91c1c; }
    .row-total { font-weight: 700; background: rgba(209, 213, 219, 0.5); border-top: 2px solid #9ca3af; } 

    /* Badges */
    .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
    .status-Issued { background: #dbeafe; color: #1e40af; } 
    .status-Returned { background: #d1fae5; color: #065f46; } 
    .status-Lost { background: #fee2e2; color: #991b1b; } 
    .status-Paid { background: #d1fae5; color: #065f46; } 
    .status-Pending { background: #fef3c7; color: #92400e; } 

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-history" style="color: #3b82f6;"></i> Book Borrowing History</h2>
        <form method="GET" class="search-container">
            <input type="search" name="book_uid" class="form-control" placeholder="Enter Book ID (e.g. UID-001)..." value="<?php echo htmlspecialchars($_GET['book_uid'] ?? ''); ?>" style="width: 300px;">
            <button type="submit" class="btn-main"><i class="fas fa-search"></i> Search</button>
        </form>
    </div>

    <?php if (isset($_GET['book_uid']) && !empty($_GET['book_uid'])): 
        $book_uid = trim($_GET['book_uid']);
        
        $sql_history = "
            SELECT 
                tc.issue_date, tc.due_date, tc.return_date, tc.status,
                tm.full_name AS member_name, tm.member_uid,
                tb.title AS book_title,
                tf.fine_amount, tf.payment_status
            FROM tbl_circulation tc
            JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
            JOIN tbl_books tb ON tbc.book_id = tb.book_id
            JOIN tbl_members tm ON tc.member_id = tm.member_id
            LEFT JOIN tbl_fines tf ON tc.circulation_id = tf.circulation_id
            WHERE tbc.book_uid = ? 
            $library_filter_sql
            ORDER BY tc.issue_date DESC
        ";
        
        $stmt = $conn->prepare($sql_history);
        $stmt->bind_param("s", $book_uid);
        $stmt->execute();
        $history_result = $stmt->get_result();
        $first_row = $history_result->fetch_assoc(); 
        $history_result->data_seek(0); 
    ?>
        <?php if ($history_result->num_rows > 0): ?>
            <h3 style="margin: 0 0 15px 0; color: #111827; font-size: 1.2rem;">
                Results for: <strong><?php echo htmlspecialchars($first_row['book_title']); ?></strong> (<?php echo htmlspecialchars($book_uid); ?>)
            </h3>
            <div class="table-container">
                <table class="glass-table">
                    <thead>
                        <tr>
                            <th>Borrower</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Return Date</th>
                            <th>Status</th>
                            <th>Fine Info</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($record = $history_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($record['member_name']); ?></div>
                                    <div style="font-size:0.8rem; color:#4b5563;"><?php echo htmlspecialchars($record['member_uid']); ?></div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($record['issue_date'])); ?></td>
                                <td><?php echo date('M d, Y', strtotime($record['due_date'])); ?></td>
                                <td><?php echo $record['return_date'] ? date('M d, Y', strtotime($record['return_date'])) : '-'; ?></td>
                                <td><span class="status-badge status-<?php echo $record['status']; ?>"><?php echo $record['status']; ?></span></td>
                                <td>
                                    <?php if ($record['fine_amount']): ?>
                                        <div style="font-weight:600;"><?php echo $currency . number_format($record['fine_amount'], 2); ?></div>
                                        <span class="status-badge status-<?php echo $record['payment_status']; ?>" style="font-size:0.65rem; margin-top:3px; display:inline-block;"><?php echo $record['payment_status']; ?></span>
                                    <?php else: ?>
                                        <span style="color:#9ca3af;">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:20px; color:#4b5563;">
                No history found for Book ID: <strong><?php echo htmlspecialchars($book_uid); ?></strong> in your library.
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-book-reader" style="color: #10b981;"></i> Currently Issued Books</h2>
    </div>
    <?php
    $sql_issued = "
        SELECT tc.issue_date, tc.due_date, tm.full_name AS member_name, tm.member_uid, tb.title AS book_title, tbc.book_uid
        FROM tbl_circulation tc
        JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
        JOIN tbl_books tb ON tbc.book_id = tb.book_id
        JOIN tbl_members tm ON tc.member_id = tm.member_id
        WHERE tc.status = 'Issued'
        $library_filter_sql
        ORDER BY tc.due_date ASC
    ";
    $issued_result = $conn->query($sql_issued);
    ?>
    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Book Details</th>
                    <th>Issued To</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($issued_result->num_rows > 0): ?>
                    <?php while ($record = $issued_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600; color:#1f2937;"><?php echo htmlspecialchars($record['book_title']); ?></div>
                                <div style="font-size:0.8rem; color:#4b5563;"><?php echo htmlspecialchars($record['book_uid']); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($record['member_name']); ?></div>
                                <div style="font-size:0.8rem; color:#4b5563;"><?php echo htmlspecialchars($record['member_uid']); ?></div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($record['issue_date'])); ?></td>
                            <td><strong style="color:#3b82f6;"><?php echo date('M d, Y', strtotime($record['due_date'])); ?></strong></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; padding: 20px; color: #6b7280;">No books currently issued in your library.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i> Overdue Books</h2>
    </div>
    <?php
    $sql_overdue = "
        SELECT tc.issue_date, tc.due_date, tm.full_name AS member_name, tm.member_uid, tb.title AS book_title, tbc.book_uid,
        DATEDIFF(CURDATE(), tc.due_date) AS days_overdue
        FROM tbl_circulation tc
        JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
        JOIN tbl_books tb ON tbc.book_id = tb.book_id
        JOIN tbl_members tm ON tc.member_id = tm.member_id
        WHERE tc.status = 'Issued' AND tc.due_date < CURDATE()
        $library_filter_sql
        ORDER BY tc.due_date ASC
    ";
    $overdue_result = $conn->query($sql_overdue);
    ?>
    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Book Details</th>
                    <th>Issued To</th>
                    <th>Due Date</th>
                    <th>Days Overdue</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($overdue_result->num_rows > 0): ?>
                    <?php while ($record = $overdue_result->fetch_assoc()): ?>
                        <tr class="row-danger">
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($record['book_title']); ?></div>
                                <div style="font-size:0.8rem;"><?php echo htmlspecialchars($record['book_uid']); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($record['member_name']); ?></div>
                                <div style="font-size:0.8rem;"><?php echo htmlspecialchars($record['member_uid']); ?></div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($record['due_date'])); ?></td>
                            <td style="font-weight:700;"><?php echo $record['days_overdue']; ?> Days</td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; padding: 20px; color: #6b7280;">No overdue books found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-money-bill-wave" style="color: #f59e0b;"></i> Fine Collection Report</h2>
    </div>
    
    <form method="GET" class="filter-form">
        <label style="color:#1f2937; font-weight:600;">From:</label>
        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($_GET['start_date'] ?? date('Y-m-01')); ?>">
        
        <label style="color:#1f2937; font-weight:600;">To:</label>
        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($_GET['end_date'] ?? date('Y-m-d')); ?>">
        
        <button type="submit" class="btn-main btn-filter"><i class="fas fa-filter"></i> Apply Filter</button>
    </form>

    <?php
    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-d');

    // Updated Query to fetch Fine ID and Library Initials
    $sql_fines = "
        SELECT 
            tf.fine_id, tf.fine_amount, tf.paid_on, tf.payment_method, 
            tm.full_name AS member_name, tm.member_uid, 
            ta.full_name AS collected_by,
            l.library_initials
        FROM tbl_fines tf
        JOIN tbl_members tm ON tf.member_id = tm.member_id
        JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
        JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
        JOIN tbl_books tb ON tbc.book_id = tb.book_id
        LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
        LEFT JOIN tbl_admin ta ON tf.collected_by_admin_id = ta.admin_id
        WHERE tf.payment_status = 'Paid' 
        AND tf.paid_on BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
        $library_filter_sql
        ORDER BY tf.paid_on DESC
    ";
    
    $stmt = $conn->prepare($sql_fines);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $fines_result = $stmt->get_result();
    $total_collected = 0;
    ?>
    
    <div class="table-container" style="margin-top: 20px;">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Fine ID</th>
                    <th>Member</th>
                    <th>Amount</th>
                    <th>Date Paid</th>
                    <th>Method</th>
                    <th>Collected By</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($fines_result->num_rows > 0): ?>
                    <?php while ($record = $fines_result->fetch_assoc()): 
                        $total_collected += $record['fine_amount'];
                        // Format Fine ID: {INST}/{LIB}/{FINE}/{ID}
                        $libInit = $record['library_initials'] ?: 'LIB';
                        $formatted_id = strtoupper($inst_init . '/' . $libInit . '/FINE/' . $record['fine_id']);
                    ?>
                        <tr>
                            <td><strong style="font-family:monospace; color:#4f46e5;"><?php echo $formatted_id; ?></strong></td>
                            <td><?php echo htmlspecialchars($record['member_name']) . " (" . htmlspecialchars($record['member_uid']) . ")"; ?></td>
                            <td style="font-weight:600; color:#10b981;"><?php echo $currency . number_format($record['fine_amount'], 2); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($record['paid_on'])); ?></td>
                            <td><?php echo htmlspecialchars($record['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($record['collected_by'] ?? 'System'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                    <tr class="row-total">
                        <td colspan="2" style="text-align: right;">TOTAL COLLECTED:</td>
                        <td colspan="4" style="color:#10b981; font-size:1.1rem;"><?php echo $currency . number_format($total_collected, 2); ?></td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 20px; color: #6b7280;">No fines collected in this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="glass-card" style="border-top: 5px solid #6b7280;">
    <div class="card-header">
        <h2><i class="fas fa-archive" style="color: #6b7280;"></i> Archived Fines Report (Cancelled)</h2>
    </div>
    
    <form method="GET" class="search-container" style="margin-bottom: 20px;">
        <input type="search" name="archived_search" class="form-control" placeholder="Search Member, Fine ID or Reason..." value="<?php echo htmlspecialchars($_GET['archived_search'] ?? ''); ?>" style="width: 350px;">
        <button type="submit" class="btn-main" style="background: #6b7280;"><i class="fas fa-search"></i> Search Archive</button>
    </form>

    <?php
    $archived_search = trim($_GET['archived_search'] ?? '');
    
    $sql_archived = "
        SELECT 
            taf.fine_id, taf.fine_amount, taf.fine_type, taf.archive_reason, taf.archived_at,
            tm.full_name AS member_name, tm.member_uid,
            ta.full_name AS archived_by_name,
            l.library_initials
        FROM tbl_archived_fines taf
        JOIN tbl_members tm ON taf.member_id = tm.member_id
        JOIN tbl_circulation tc ON taf.circulation_id = tc.circulation_id
        JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
        JOIN tbl_books tb ON tbc.book_id = tb.book_id
        LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
        LEFT JOIN tbl_admin ta ON taf.archived_by = ta.admin_id
        WHERE 1=1 
        $library_filter_sql
    ";

    $arch_params = [];
    $arch_types = "";

    if (!empty($library_filter_sql)) {
        // If a library filter is active (from $library_filter_sql variable above), we reuse the $my_lib_id
        $arch_params[] = $my_lib_id;
        $arch_types .= "i";
    }

    if (!empty($archived_search)) {
        $sql_archived .= " AND (tm.full_name LIKE ? OR tm.member_uid LIKE ? OR taf.archive_reason LIKE ? OR taf.fine_id = ?)";
        $term = "%" . $archived_search . "%";
        $arch_params = array_merge($arch_params, [$term, $term, $term, $archived_search]);
        $arch_types .= "ssss";
    }

    $sql_archived .= " ORDER BY taf.archived_at DESC LIMIT 20"; // Limit for display

    $stmt_arch = $conn->prepare($sql_archived);
    if (!empty($arch_params)) {
        $stmt_arch->bind_param($arch_types, ...$arch_params);
    }
    $stmt_arch->execute();
    $archived_result = $stmt_arch->get_result();
    ?>

    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Fine ID</th>
                    <th>Member</th>
                    <th>Amount</th>
                    <th>Original Reason</th>
                    <th>Archived By</th>
                    <th>Cancellation Reason</th>
                    <th>Date Archived</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($archived_result->num_rows > 0): ?>
                    <?php while ($row = $archived_result->fetch_assoc()): 
                        // Format Fine ID: {INST}/{LIB}/{FINE}/{ID}
                        $libInit = $row['library_initials'] ?: 'LIB';
                        $formatted_id = strtoupper($inst_init . '/' . $libInit . '/FINE/' . $row['fine_id']);
                    ?>
                        <tr>
                            <td><strong style="font-family:monospace; color:#6b7280;"><?php echo $formatted_id; ?></strong></td>
                            <td>
                                <div style="font-weight:600;"><?php echo htmlspecialchars($row['member_name']); ?></div>
                                <div style="font-size:0.8rem; color:#6b7280;"><?php echo htmlspecialchars($row['member_uid']); ?></div>
                            </td>
                            <td style="color:#ef4444; font-weight:600;"><?php echo $currency . number_format($row['fine_amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($row['fine_type']); ?></td>
                            <td><?php echo htmlspecialchars($row['archived_by_name'] ?? 'System'); ?></td>
                            <td style="color:#4b5563; font-style:italic;">"<?php echo htmlspecialchars($row['archive_reason']); ?>"</td>
                            <td><?php echo date('d M Y', strtotime($row['archived_at'])); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 20px; color: #6b7280;">No archived fines found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php admin_footer(); close_db_connection($conn); ?>