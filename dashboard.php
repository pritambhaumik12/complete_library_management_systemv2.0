<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];
$currency = get_setting($conn, 'currency_symbol');

// --- Fetch Member Details ---
$stmt = $conn->prepare("SELECT * FROM tbl_members WHERE member_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member_details = $stmt->get_result()->fetch_assoc();

// --- Handle Profile Update/Password Change ---
$message = '';
$error = '';
$msg_type = ''; // 'success' or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');

        $stmt = $conn->prepare("UPDATE tbl_members SET full_name = ?, email = ?, department = ? WHERE member_id = ?");
        $stmt->bind_param("sssi", $full_name, $email, $department, $member_id);
        if ($stmt->execute()) {
            $_SESSION['member_full_name'] = $full_name;
            $member_details['full_name'] = $full_name;
            $member_details['email'] = $email;
            $member_details['department'] = $department;
            $message = "Profile details updated successfully.";
            $msg_type = 'success';
        } else {
            $message = "Failed to update profile.";
            $msg_type = 'error';
        }
    } elseif ($action === 'change_password') {
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        if ($new_password !== $confirm_password) {
            $message = "Passwords do not match.";
            $msg_type = 'error';
        } elseif (strlen($new_password) < 6) {
            $message = "Password must be at least 6 characters.";
            $msg_type = 'error';
        } else {
            $stmt = $conn->prepare("UPDATE tbl_members SET password = ? WHERE member_id = ?");
            $stmt->bind_param("si", $new_password, $member_id);
            if ($stmt->execute()) {
                $message = "Password changed successfully.";
                $msg_type = 'success';
            } else {
                $message = "Error changing password.";
                $msg_type = 'error';
            }
        }
    }
}

// --- Fetch Borrowed Books (Current Loans) ---
$sql_borrowed = "SELECT tc.issue_date, tc.due_date, tb.title, tb.author, tbc.book_uid 
                 FROM tbl_circulation tc
                 JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                 JOIN tbl_books tb ON tbc.book_id = tb.book_id
                 WHERE tc.member_id = ? AND tc.status = 'Issued'
                 ORDER BY tc.due_date ASC";
$stmt = $conn->prepare($sql_borrowed);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$borrowed_books = $stmt->get_result();
$borrowed_count = $borrowed_books->num_rows; 

// --- Fetch Outstanding Fines (Details) ---
$sql_outstanding_fines = "
    SELECT 
        tf.fine_id, tf.fine_amount, tf.fine_date, tf.fine_type,
        tb.title AS book_title, tbc.book_uid
    FROM 
        tbl_fines tf
    JOIN 
        tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    JOIN 
        tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN 
        tbl_books tb ON tbc.book_id = tb.book_id
    WHERE 
        tf.member_id = ? AND tf.payment_status = 'Pending'
    ORDER BY 
        tf.fine_date DESC";
$stmt = $conn->prepare($sql_outstanding_fines);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$outstanding_fines = $stmt->get_result();

// --- Fetch Paid Fines (History) ---
$sql_paid_fines = "
    SELECT 
        tf.fine_id, tf.fine_amount, tf.paid_on, tf.payment_method, tf.fine_type,
        tb.title AS book_title, tbc.book_uid
    FROM 
        tbl_fines tf
    JOIN 
        tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    JOIN 
        tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN 
        tbl_books tb ON tbc.book_id = tb.book_id
    WHERE 
        tf.member_id = ? AND tf.payment_status = 'Paid'
    ORDER BY 
        tf.paid_on DESC";
$stmt = $conn->prepare($sql_paid_fines);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$paid_fines = $stmt->get_result();

// --- Fetch All Borrowing History (For History Tab) ---
$sql_all_history = "
    SELECT 
        tc.issue_date, tc.due_date, tc.return_date, tc.status,
        tb.title, tbc.book_uid
    FROM 
        tbl_circulation tc
    JOIN 
        tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN 
        tbl_books tb ON tbc.book_id = tb.book_id
    WHERE 
        tc.member_id = ? 
    ORDER BY 
        tc.issue_date DESC";
$stmt = $conn->prepare($sql_all_history);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$all_history = $stmt->get_result();
$history_count = $all_history->num_rows; 

// --- Calculate Total Outstanding Fine Sum (For Stat Card) ---
$total_fine = 0;
$outstanding_fines->data_seek(0); // Reset pointer
while($row = $outstanding_fines->fetch_assoc()) {
    $total_fine += $row['fine_amount'];
}
$outstanding_fines->data_seek(0); // Reset pointer again

user_header('My Dashboard');

// --- NEW PHP LOGIC: Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';
?>

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    /* UPDATED ROOT FOR HIGH CONTRAST */
    :root {
        --primary: #4361ee;
        --secondary: #3f37c9;
        --accent: #4cc9f0;
        --text-dark: #111827; /* DEEP BLACK for High Contrast */
        --text-light: #4b5563; /* Darker muted text for contrast */
        --success: #2ec4b6;
        --danger: #ef233c;
        --warning: #ff9f1c;
        --card-bg: rgba(255, 255, 255, 0.7); /* White Transparent Glass */
        --shadow: 0 10px 30px rgba(0,0,0,0.05);
        --input-border: #d1d5db;
    }

    /* UPDATED BODY FOR DARK BACKGROUND AND WATERMARK */
    body {
        font-family: 'Poppins', sans-serif;
        color: var(--text-dark);
        /* Admin Dashboard Dark Gradient Background */
        background: radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                    radial-gradient(at 50% 100%, hsla(225,39%,25%,1) 0, transparent 50%), 
                    radial-gradient(at 100% 0%, hsla(339,49%,25%,1) 0, transparent 50%),
                    #0f172a;
        background-attachment: fixed; 
        background-size: cover;
        position: relative; 
    }

    /* Watermark CSS */
    <?php if (!empty($full_bg_logo_path)): ?>
        body::before {
            content: "";
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 80%;
            background-image: url("<?php echo $full_bg_logo_path; ?>");
            background-repeat: no-repeat;
            background-position: center center;
            background-size: contain;
            opacity: 0.3; 
            z-index: -1; 
            pointer-events: none;
        }
    <?php endif; ?>
    /* End Watermark CSS */

    .dashboard-container {
        max-width: 1200px;
        margin: 30px auto;
        padding: 0 20px;
    }

    /* --- Welcome Section --- */
    .welcome-banner {
        background: linear-gradient(135deg, rgba(67, 97, 238, 0.9), rgba(63, 55, 201, 0.9));
        border-radius: 20px;
        padding: 30px;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        box-shadow: 0 10px 25px rgba(67, 97, 238, 0.3);
    }

    .date-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 10px 20px;
        border-radius: 12px;
        backdrop-filter: blur(5px);
        font-weight: 600;
    }

    /* --- Stats Grid --- */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    /* APPLY GLASS EFFECT */
    .stat-card {
        background: var(--card-bg); /* Use transparent white */
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.5); /* Subtle white border */
        border-radius: 15px;
        padding: 20px;
        display: flex;
        align-items: center;
        gap: 20px;
        box-shadow: 0 8px 15px rgba(0,0,0,0.2); /* Darker shadow */
        transition: transform 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 1.5rem;
    }

    .stat-info h3 {
        font-size: 2rem;
        font-weight: 700;
        color: var(--text-dark); /* Deep Black Text */
        line-height: 1;
        margin-bottom: 5px;
    }

    .stat-info p {
        color: var(--text-light); /* Dark Muted Text */
        font-size: 0.9rem;
        font-weight: 500;
    }

    /* --- Main Content Grid --- */
    .content-grid {
        display: grid;
        grid-template-columns: 1fr 2fr; /* 1/3 Profile, 2/3 Data */
        gap: 30px;
    }

    @media (max-width: 900px) {
        .content-grid { grid-template-columns: 1fr; }
    }

    /* --- Common Card Style & Glass Effect --- */
    .dash-card {
        background: var(--card-bg); /* Use transparent white */
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.5); /* Subtle white border */
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 8px 15px rgba(0,0,0,0.2); /* Darker shadow */
        height: 100%;
        display: flex;
        flex-direction: column;
    }

    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        border-bottom: 1px solid rgba(0,0,0,0.1); /* Darker border for contrast */
        padding-bottom: 15px;
    }

    .card-header h2 {
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--text-dark); /* Deep Black Text */
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    /* --- Profile Section (LEFT CARD) --- */
    .profile-tabs {
        display: flex;
        gap: 15px;
        margin-bottom: 25px;
        background: rgba(241, 243, 245, 0.7); /* Slightly transparent background for tabs */
        padding: 5px;
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .tab-btn {
        flex: 1;
        border: none;
        background: transparent;
        padding: 10px;
        border-radius: 10px;
        cursor: pointer;
        font-weight: 600;
        color: var(--text-light);
        transition: 0.3s;
    }

    .tab-btn.active {
        background: white;
        color: var(--primary);
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    
    /* NEW FORM STYLES */
    .form-field-group { margin-bottom: 20px; }
    .form-label { 
        display: block;
        color: var(--text-light); 
        font-weight: 600;
        font-size: 0.9rem;
        margin-bottom: 8px;
    }
    .form-control {
        width: 90%;
        padding: 12px;
        border: 2px solid var(--input-border);
        border-radius: 10px;
        font-size: 1rem;
        color: var(--text-dark); 
        background: rgba(255,255,255,0.9);
        transition: all 0.3s;
    }
    .form-control:focus {
        border-color: var(--primary);
        box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        outline: none;
        background: white;
    }
    
    .btn-submit {
        width: 100%;
        padding: 14px;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 1rem;
        cursor: pointer;
        margin-top: 20px;
        transition: all 0.3s;
        color: white;
    }

    .btn-blue { 
        background: var(--primary); 
        box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3); 
    }
    .btn-red { 
        background: var(--danger); 
        box-shadow: 0 4px 10px rgba(239, 35, 60, 0.3); 
    }
    .btn-submit:hover { transform: translateY(-2px); }


    /* --- NEW TABBED INTERFACE STYLES (for right card) --- */
    .data-tabs {
        display: flex;
        flex-wrap: wrap;
        margin-bottom: 20px;
        border-bottom: 2px solid rgba(0,0,0,0.1);
        gap: 15px;
    }
    .data-tab-btn {
        background: transparent;
        border: none;
        padding: 10px 5px;
        cursor: pointer;
        font-weight: 600;
        color: var(--text-light);
        border-bottom: 2px solid transparent;
        transition: all 0.2s;
    }
    .data-tab-btn.active {
        color: var(--primary);
        border-bottom-color: var(--primary);
    }
    .data-tab-btn:hover {
        color: var(--text-dark);
    }
    .data-tab-content {
        display: none;
        animation: fadeIn 0.3s;
        flex-grow: 1; /* Allows content to push footer down */
    }
    .data-tab-content.active {
        display: flex; /* Use flex to manage height/overflow */
        flex-direction: column;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    /* Fine-specific styles */
    .fine-amount-display {
        font-size: 1.8rem; /* Slightly larger */
        font-weight: 700;
        color: var(--danger);
        padding: 15px 25px; /* Better padding */
        background: rgba(239, 35, 60, 0.1);
        border-radius: 12px;
        margin-bottom: 20px;
        display: inline-block;
        border: 1px solid rgba(239, 35, 60, 0.2);
    }
    .fine-history-row {
        background: rgba(255, 235, 240, 0.5);
    }

    /* --- Table Styles --- */
    .custom-table {
        width: 100%;
        border-collapse: collapse;
    }
    .custom-table th { 
        color: var(--text-dark); 
        background: rgba(67, 97, 238, 0.1); 
        padding: 12px 15px;
        text-align: left;
        font-size: 0.85rem;
        font-weight: 700;
        text-transform: uppercase;
    }
    .custom-table td { 
        color: var(--text-dark); 
        padding: 12px 15px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        font-size: 0.95rem;
    }
    .custom-table tr:hover { background: rgba(0,0,0,0.03); }
    
    .status-badge {
        padding: 5px 10px;
        border-radius: 10px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
    }
    .badge-issued { background: rgba(76, 201, 240, 0.15); color: #0096c7; }
    .badge-overdue { background: rgba(239, 35, 60, 0.15); color: var(--danger); }
    .badge-returned { background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .badge-paid { background: rgba(16, 185, 129, 0.15); color: #10b981; }
    .badge-pending { background: rgba(239, 35, 60, 0.15); color: var(--danger); }
    
    /* NEW: History Button Style */
    .btn-history {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        background: var(--primary);
        color: white;
        padding: 12px 25px;
        border-radius: 10px;
        font-weight: 600;
        transition: all 0.3s;
        box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
    }
    .btn-history:hover {
        background: var(--secondary);
        transform: translateY(-2px);
    }
</style>

<div class="dashboard-container">
    
    <div class="welcome-banner">
        <div class="welcome-text">
            <h1>Hello, <?php echo htmlspecialchars($member_details['full_name']); ?>! 👋</h1>
            <p>Welcome to your library dashboard. Here is your activity overview.</p>
        </div>
        <div class="date-badge">
            <i class="far fa-calendar-alt"></i> <?php echo date("F j, Y"); ?>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: #e0f3ff; color: var(--primary);">
                <i class="fas fa-book-reader"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $borrowed_count; ?></h3>
                <p>Books Borrowed</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #ffe3e6; color: var(--danger);">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $currency . number_format($total_fine, 2); ?></h3>
                <p>Outstanding Fines</p>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: #e3f9f5; color: var(--success);">
                <i class="fas fa-history"></i>
            </div>
            <div class="stat-info">
                <h3><?php echo $history_count; ?></h3>
                <p>Circulation History</p>
            </div>
        </div>
    </div>

    <div class="content-grid">
        
        <div class="dash-card">
            <div class="card-header">
                <h2><i class="fas fa-user-cog" style="color: var(--primary);"></i> Account Settings</h2>
            </div>

            <?php if ($message): ?>
                <div class="alert-box alert-<?php echo $msg_type; ?>" style="background: <?php echo $msg_type == 'success' ? '#d1fae5' : '#fee2e2'; ?>; color: <?php echo $msg_type == 'success' ? '#065f46' : '#991b1b'; ?>; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
                    <i class="fas <?php echo $msg_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="profile-tabs">
                <button class="tab-btn active" onclick="switchTab('details')"><i class="fas fa-id-card-alt"></i> Profile Details</button>
                <button class="tab-btn" onclick="switchTab('password')"><i class="fas fa-lock"></i> Security</button>
            </div>

            <div id="tab-details" class="tab-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-field-group">
                        <label class="form-label">Member ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($member_details['member_uid']); ?>" disabled style="background: #eef1f5; color: #adb5bd;">
                    </div>

                    <div class="form-field-group">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($member_details['full_name']); ?>" required>
                    </div>

                    <div class="form-field-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($member_details['email']); ?>">
                    </div>

                    <div class="form-field-group">
                        <label class="form-label">Department</label>
                        <input type="text" name="department" class="form-control" value="<?php echo htmlspecialchars($member_details['department']); ?>" required>
                    </div>

                    <button type="submit" class="btn-submit btn-blue">
                        <i class="fas fa-save"></i> Save Profile
                    </button>
                </form>
            </div>

            <div id="tab-password" class="tab-content" style="display: none;">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-field-group">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Min 6 characters" required>
                    </div>

                    <div class="form-field-group">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" placeholder="Re-enter password" required>
                    </div>

                    <button type="submit" class="btn-submit btn-red">
                        <i class="fas fa-exchange-alt"></i> Change Password
                    </button>
                </form>
            </div>
        </div>

        <div class="dash-card">
            <div class="card-header">
                <h2><i class="fas fa-database" style="color: var(--accent);"></i> Circulation & Fine Records</h2>
            </div>
            
            <div class="data-tabs">
                <button class="data-tab-btn active" onclick="switchDataTab(event, 'currentLoans')"><i class="fas fa-book-open"></i> Current Loans</button>
                <button class="data-tab-btn" onclick="switchDataTab(event, 'borrowingHistory')"><i class="fas fa-history"></i> History</button>
                <button class="data-tab-btn" onclick="switchDataTab(event, 'outstandingFines')"><i class="fas fa-exclamation-triangle"></i> Dues</button>
                <button class="data-tab-btn" onclick="switchDataTab(event, 'fineHistory')"><i class="fas fa-receipt"></i> Fine History</button>
            </div>

            <div id="currentLoans" class="data-tab-content active">
                <div style="overflow-x: auto; width: 100%;">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Book Title</th>
                                <th>Issue Date</th>
                                <th>Due Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($borrowed_books->num_rows > 0): ?>
                                <?php $borrowed_books->data_seek(0); while ($book = $borrowed_books->fetch_assoc()): ?>
                                    <?php 
                                        $is_overdue = strtotime($book['due_date']) < time();
                                        $status_class = $is_overdue ? 'badge-overdue' : 'badge-issued';
                                        $status_text = $is_overdue ? 'Overdue' : 'On Time';
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($book['title']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-light);">UID: <?php echo htmlspecialchars($book['book_uid']); ?></div>
                                        </td>
                                        <td style="font-weight: 500;"><?php echo date('M d, Y', strtotime($book['issue_date'])); ?></td>
                                        <td style="font-weight: 600; color: <?php echo $is_overdue ? 'var(--danger)' : 'var(--text-dark)'; ?>">
                                            <?php echo date('M d, Y', strtotime($book['due_date'])); ?>
                                            <?php if($is_overdue): ?>
                                                <div style="font-size: 0.75rem; color: var(--danger);">Late by <?php echo floor((time() - strtotime($book['due_date'])) / (60 * 60 * 24)); ?> days</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" style="text-align: center; padding: 20px 0; color: var(--text-light);">No books currently borrowed.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="borrowingHistory" class="data-tab-content" style="align-items: center; justify-content: center; text-align: center; height: 100%;">
                <p style="color: var(--text-dark); margin-bottom: 20px; font-weight: 500; font-size: 1.1rem; width: 100%;">View your complete record of past issues and returns.</p>
                <a href="history.php" class="btn-history">
                    <i class="fas fa-arrow-right"></i> View Borrowing History
                </a>
            </div>

            <div id="outstandingFines" class="data-tab-content" style="align-items: center; justify-content: center;">
                <?php if ($total_fine > 0): ?>
                    <div style="text-align: center; width: 100%;">
                        <p style="color: var(--text-dark); font-weight: 600; margin-bottom: 10px; font-size: 1.1rem;"><i class="fas fa-hand-holding-usd" style="color: var(--danger);"></i> Total Fine Outstanding</p>
                        <div class="fine-amount-display"><?php echo $currency . number_format($total_fine, 2); ?></div>
                        <p style="font-size: 0.9rem; color: var(--text-light); max-width: 300px; margin: 0 auto 30px;"><i class="fas fa-info-circle"></i> Please visit the librarian to clear your dues as soon as possible.</p>
                    </div>
                    <div style="overflow-x: auto; width: 100%;">
                        <table class="custom-table">
                            <thead>
                                <tr>
                                    <th>Fine ID</th>
                                    <th>Book Title</th>
                                    <th>Type</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $outstanding_fines->data_seek(0); while ($fine = $outstanding_fines->fetch_assoc()): ?>
                                    <tr class="fine-history-row">
                                        <td>#<?php echo $fine['fine_id']; ?></td>
                                        <td><?php echo htmlspecialchars($fine['book_title']); ?></td>
                                        <td><?php echo htmlspecialchars($fine['fine_type']); ?></td>
                                        <td style="color: var(--danger); font-weight: 700;"><?php echo $currency . number_format($fine['fine_amount'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; padding: 20px 0; color: #10b981; font-weight: 600; width: 100%;"><i class="fas fa-smile-beam"></i> You have no outstanding fines!</p>
                <?php endif; ?>
            </div>

            <div id="fineHistory" class="data-tab-content" style="align-items: center; justify-content: center; text-align: center; height: 100%;">
                <p style="color: var(--text-dark); margin-bottom: 20px; font-weight: 500; font-size: 1.1rem; width: 100%;">View a detailed log of all fines, including outstanding and paid dues.</p>
                <a href="fines.php" class="btn-history">
                    <i class="fas fa-arrow-right"></i> View Fine History
                </a>
            </div>

        </div>

    </div>
</div>

<script>
    function switchTab(tabName) {
        // Hide all tab content
        document.getElementById('tab-details').style.display = 'none';
        document.getElementById('tab-password').style.display = 'none';
        
        // Reset buttons
        const buttons = document.querySelectorAll('.tab-btn');
        buttons.forEach(btn => btn.classList.remove('active'));

        // Show selected
        document.getElementById('tab-' + tabName).style.display = 'block';
        
        // Highlight button (simple logic based on order)
        if(tabName === 'details') buttons[0].classList.add('active');
        else buttons[1].classList.add('active');
    }

    function switchDataTab(evt, tabName) {
        // Hide all tab content
        document.querySelectorAll('.data-tab-content').forEach(x => x.classList.remove('active'));
        document.querySelectorAll('.data-tab-btn').forEach(x => x.classList.remove('active'));
        
        // Show selected content and activate button
        document.getElementById(tabName).classList.add('active');
        evt.currentTarget.classList.add('active');
    }

    // Initial activation on load
    document.addEventListener('DOMContentLoaded', function() {
        // Activate default Profile tab
        document.querySelector('.tab-btn').click(); 
        // Activate default Data tab
        document.querySelector('.data-tab-btn').click();
    });
</script>

<?php
user_footer();
close_db_connection($conn);
?>