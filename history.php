<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];
$currency = get_setting($conn, 'currency_symbol');

// --- NEW PHP LOGIC: Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';


// --- Fetch Borrowing History ---
$sql_history = "
    SELECT 
        tc.issue_date, tc.due_date, tc.return_date, tc.status,
        tb.title, tb.author, tb.category,
        tbc.book_uid,
        tf.fine_amount, tf.payment_status,
        l.library_name
    FROM 
        tbl_circulation tc
    JOIN 
        tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN 
        tbl_books tb ON tbc.book_id = tb.book_id
    LEFT JOIN
        tbl_libraries l ON tb.library_id = l.library_id
    LEFT JOIN
        tbl_fines tf ON tc.circulation_id = tf.circulation_id
    WHERE 
        tc.member_id = ?
    ORDER BY 
        tc.issue_date DESC
";
$stmt = $conn->prepare($sql_history);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$history_result = $stmt->get_result();

user_header('My Borrowing History');
?>

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* --- SHARED VARIABLES (Deep Black Text, Transparent White Glass) --- */
    :root {
        --primary: #4361ee;
        --secondary: #3f37c9;
        --accent: #4cc9f0;
        --text-dark: #111827; /* DEEP BLACK for High Contrast */
        --text-light: #4b5563; /* Darker muted text for contrast */
        --success: #10b981;
        --danger: #ef233c;
        --warning: #f72585;
        --white: #ffffff;
        --card-bg: rgba(255, 255, 255, 0.75); /* White Transparent Glass */
        --shadow: 0 10px 40px rgba(0,0,0,0.3);
        --shadow-hover: 0 15px 35px rgba(67, 97, 238, 0.15);
    }

    body {
        font-family: 'Poppins', sans-serif;
        color: var(--text-dark);
        /* Dark Gradient Background (Matching User Portal) */
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

    .history-container {
        max-width: 1000px;
        margin: 40px auto;
        padding: 0 20px;
    }

    /* --- HEADER SECTION --- */
    .page-header {
        text-align: center;
        margin-bottom: 40px;
        animation: fadeInDown 0.6s ease-out;
        color: var(--white); /* White text for header against dark background */
    }

    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .page-header h1 {
        font-family: 'Nunito', sans-serif;
        font-weight: 800;
        font-size: 2.2rem;
        color: var(--white);
        text-shadow: 0 0 5px rgba(0,0,0,0.5);
        margin-bottom: 10px;
    }

    .page-header p {
        color: #cbd5e1;
        font-size: 1rem;
    }

    .record-count {
        display: inline-block;
        background: #e0f3ff;
        color: var(--primary);
        padding: 5px 15px;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 700;
        margin-top: 10px;
    }

    /* --- TIMELINE CARD LIST --- */
    .timeline-list {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .history-card {
        background: var(--card-bg); /* Glass Effect */
        backdrop-filter: blur(10px); 
        -webkit-backdrop-filter: blur(10px);
        border-radius: 16px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2); 
        display: grid;
        grid-template-columns: 80px 1fr auto; /* Date | Details | Status */
        overflow: hidden;
        transition: all 0.3s ease;
        border: 1px solid rgba(255, 255, 255, 0.8);
        position: relative;
    }

    .history-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--primary);
    }

    /* 1. Left Date Box */
    .date-box {
        background: rgba(248, 249, 250, 0.7); /* Slightly opaque */
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 15px;
        border-right: 1px solid rgba(0,0,0,0.1);
        text-align: center;
    }

    .date-month {
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-light);
    }

    .date-day {
        font-size: 1.8rem;
        font-weight: 800;
        color: var(--primary);
        line-height: 1;
    }

    .date-year {
        font-size: 0.7rem;
        color: var(--text-light);
    }

    /* 2. Middle Details */
    .card-details {
        padding: 20px;
        display: flex;
        flex-direction: column;
        justify-content: center;
    }

    .book-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-dark); /* Deep Black */
        margin-bottom: 5px;
    }

    .book-meta {
        font-size: 0.9rem;
        color: var(--text-light);
        margin-bottom: 10px;
    }

    .book-uid-badge {
        display: inline-block;
        background: rgba(0,0,0,0.05);
        padding: 3px 8px;
        border-radius: 6px;
        font-family: monospace;
        font-size: 0.8rem;
        color: var(--text-dark); /* Deep Black */
        border: 1px solid rgba(0,0,0,0.1);
    }

    /* 3. Right Status & Fines */
    .card-status {
        padding: 20px;
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        justify-content: center;
        background: rgba(248, 249, 250, 0.5); /* Subtle distinction */
        border-left: 1px solid rgba(0,0,0,0.1);
        min-width: 180px;
    }

    .status-pill {
        padding: 6px 15px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    /* Status colors remain high contrast */
    .st-returned { background: #d1fae5; color: #059669; }
    .st-issued { background: #fff7ed; color: #ea580c; }
    .st-lost { background: #fee2e2; color: #dc2626; }

    .date-info {
        font-size: 0.8rem;
        color: var(--text-light);
        text-align: right;
    }

    .fine-alert {
        margin-top: 8px;
        font-size: 0.85rem;
        font-weight: 600;
        color: var(--danger);
        display: flex;
        align-items: center;
        gap: 5px;
    }

    /* Fine Paid/Unpaid Badges */
    .fine-alert span {
        font-weight: 700;
    }
    .fine-alert span[style*="--success"] { background: #d1fae5 !important; color: var(--success) !important; }
    .fine-alert span[style*="--danger"] { background: #fee2e2 !important; color: var(--danger) !important; }


    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px;
        color: var(--white);
        background: rgba(0,0,0,0.3);
        border-radius: 15px;
    }
    .empty-state i { font-size: 3rem; opacity: 0.5; margin-bottom: 15px; color: var(--white); }
    .empty-state h2 { color: var(--white); }
    .empty-state p a { color: var(--accent); }

    /* Responsive */
    @media (max-width: 768px) {
        .history-card { grid-template-columns: 1fr; grid-template-rows: auto auto auto; }
        .date-box { flex-direction: row; gap: 10px; border-right: none; border-bottom: 1px solid rgba(0,0,0,0.1); padding: 10px; justify-content: flex-start; }
        .date-day { font-size: 1.2rem; }
        .card-status { flex-direction: row; justify-content: space-between; align-items: center; border-left: none; border-top: 1px solid rgba(0,0,0,0.1); }
    }
</style>

<div class="history-container">

    <div class="page-header">
        <h1>Reading Timeline</h1>
        <p>A complete record of your journey through our library.</p>
        <div class="record-count">
            <i class="fas fa-list"></i> <?php echo $history_result->num_rows; ?> Records
        </div>
    </div>

    <div class="timeline-list">
        <?php if ($history_result->num_rows > 0): ?>
            <?php while ($row = $history_result->fetch_assoc()): 
                // Date Parsing
                $issueTimestamp = strtotime($row['issue_date']);
                $day = date('d', $issueTimestamp);
                $month = date('M', $issueTimestamp);
                $year = date('Y', $issueTimestamp);

                // Status Logic
                $statusClass = 'st-issued';
                $icon = 'fa-book-reader';
                if ($row['status'] === 'Returned') { 
                    $statusClass = 'st-returned'; 
                    $icon = 'fa-check-circle';
                } elseif ($row['status'] === 'Lost') { 
                    $statusClass = 'st-lost'; 
                    $icon = 'fa-times-circle';
                }
            ?>
            
            <div class="history-card">
                <div class="date-box">
                    <span class="date-month"><?php echo $month; ?></span>
                    <span class="date-day"><?php echo $day; ?></span>
                    <span class="date-year"><?php echo $year; ?></span>
                </div>

                <div class="card-details">
                    <div class="book-title"><?php echo htmlspecialchars($row['title']); ?></div>
                    <div class="book-meta">
                        <span>by <?php echo htmlspecialchars($row['author']); ?></span> &bull; 
                        <span><?php echo htmlspecialchars($row['category']); ?></span> &bull;
                        <span><i class="fas fa-university"></i> <?php echo htmlspecialchars($row['library_name'] ?? 'General'); ?></span>
                    </div>
                    <div>
                        <span class="book-uid-badge">
                            <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($row['book_uid']); ?>
                        </span>
                    </div>
                </div>

                <div class="card-status">
                    <div class="status-pill <?php echo $statusClass; ?>">
                        <i class="fas <?php echo $icon; ?>"></i> <?php echo $row['status']; ?>
                    </div>
                    
                    <div class="date-info">
                        <?php if ($row['status'] === 'Returned'): ?>
                            Returned: <?php echo date('M d, Y', strtotime($row['return_date'])); ?>
                        <?php else: ?>
                            Due: <?php echo date('M d, Y', strtotime($row['due_date'])); ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($row['fine_amount'] > 0): ?>
                        <div class="fine-alert">
                            <i class="fas fa-exclamation-circle"></i>
                            Fine: <?php echo $currency . number_format($row['fine_amount'], 2); ?>
                            <?php if ($row['payment_status'] === 'Paid'): ?>
                                <span style="color: var(--success); font-size: 0.75rem; background: #d1fae5; padding: 2px 6px; border-radius: 4px; margin-left: 5px;">PAID</span>
                            <?php else: ?>
                                <span style="color: var(--danger); font-size: 0.75rem; background: #fee2e2; padding: 2px 6px; border-radius: 4px; margin-left: 5px;">UNPAID</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-history"></i>
                <h2>No History Yet</h2>
                <p>You haven't borrowed any books yet. Visit the <a href="search.php" style="color: var(--primary);">Search Page</a> to get started!</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
user_footer();
close_db_connection($conn);
?>