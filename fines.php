<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];
$currency = get_setting($conn, 'currency_symbol');

// --- NEW PHP LOGIC: Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';

// --- MODIFICATION: Fetch Institution/Library Initials for Custom ID ---
$inst_init = get_setting($conn, 'institution_initials') ?: 'INS';
$lib_init = get_setting($conn, 'library_initials') ?: 'LIB';


// --- Fetch Fines ---
$sql_fines = "
    SELECT 
        tf.fine_id, tf.fine_amount, tf.fine_date, tf.payment_status, tf.fine_type, tf.pay_later_due_date, tf.paid_on, tf.payment_method,
        tb.title AS book_title,
        tbc.book_uid,
        l.library_name,
        l.library_initials
    FROM 
        tbl_fines tf
    JOIN 
        tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    JOIN 
        tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN 
        tbl_books tb ON tbc.book_id = tb.book_id
    LEFT JOIN
        tbl_libraries l ON tb.library_id = l.library_id
    WHERE 
        tf.member_id = ?
    ORDER BY 
        tf.fine_date DESC
";
$stmt = $conn->prepare($sql_fines);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$fines_result = $stmt->get_result();

user_header('My Fine History');
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
    
    /* --- NEW WATERMARK STYLE --- */
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

    .fines-container {
        max-width: 1000px;
        margin: 40px auto;
        padding: 0 20px;
    }

    /* --- HEADER SECTION --- */
    .page-header {
        text-align: center;
        margin-bottom: 40px;
        animation: fadeInDown 0.6s ease-out;
        color: var(--white);
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

    /* --- FINE CARD LIST (Glass Effect) --- */
    .fine-list {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .fine-card {
        background: var(--card-bg); /* Glass Effect */
        backdrop-filter: blur(15px); 
        -webkit-backdrop-filter: blur(15px);
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        display: grid;
        grid-template-columns: 1fr auto; /* Details | Status & Amount */
        overflow: hidden;
        transition: all 0.3s ease;
        border: 1px solid rgba(255, 255, 255, 0.8);
        padding: 20px;
        position: relative;
    }

    .fine-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-hover);
        border-color: var(--primary);
    }

    .fine-details {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .fine-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--text-dark); /* Deep Black */
    }

    .fine-meta {
        font-size: 0.9rem;
        color: var(--text-light); /* Darker Muted Text */
        line-height: 1.6;
    }
    
    .receipt-no {
        display: inline-block;
        background: rgba(0, 0, 0, 0.05);
        color: var(--text-dark);
        padding: 2px 8px;
        border-radius: 4px;
        font-family: monospace;
        font-weight: 600;
        font-size: 0.85rem;
        margin-bottom: 5px;
        border: 1px solid rgba(0, 0, 0, 0.1);
    }

    .fine-status-box {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        justify-content: flex-start;
        gap: 8px;
        min-width: 140px;
    }

    .fine-amount {
        font-size: 1.2rem;
        font-weight: 800;
        color: var(--danger);
    }

    .status-pill {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
    }

    .st-paid { background: #d1fae5; color: #059669; }
    .st-pending { background: #fee2e2; color: #dc2626; }

    .pay-later-info {
        font-size: 0.8rem;
        color: var(--danger); /* Use danger color for due dates */
        font-weight: 600;
        margin-top: 5px;
    }
    
    .btn-print {
        text-decoration: none;
        background: #e9ecef;
        color: #495057;
        font-size: 0.8rem;
        padding: 6px 12px;
        border-radius: 6px;
        font-weight: 600;
        transition: 0.2s;
        display: flex;
        align-items: center;
        gap: 5px;
        border: 1px solid #d1d5db;
    }
    .btn-print:hover {
        background: #d1d5db;
        color: var(--text-dark);
    }

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
    .empty-state p { color: #cbd5e1; }


    /* Responsive */
    @media (max-width: 600px) {
        .fine-card { grid-template-columns: 1fr; gap: 15px; }
        .fine-status-box { 
            align-items: flex-start; 
            border-top: 1px solid rgba(0, 0, 0, 0.1); 
            padding-top: 10px; 
            margin-top: 10px; 
            flex-direction: row; 
            justify-content: space-between; 
            width: 100%; 
        }
    }
</style>

<div class="fines-container">

    <div class="page-header">
        <h1>My Fine History</h1>
        <p>Track your overdue payments and cleared dues.</p>
    </div>

    <div class="fine-list">
        <?php if ($fines_result->num_rows > 0): ?>
            <?php while ($row = $fines_result->fetch_assoc()): ?>
                <?php 
                    // Determine Library Initial: Use specific book library initial if available, otherwise global default
                    $current_lib_init = !empty($row['library_initials']) ? $row['library_initials'] : $lib_init;
                ?>
                <div class="fine-card">
                    <div class="fine-details">
                        <div>
                            <span class="receipt-no">
                                Receipt #<?php echo htmlspecialchars(strtoupper($inst_init . '/' . $current_lib_init . '/FINE/' . $row['fine_id'])); ?>
                            </span>
                        </div>
                        <div class="fine-title">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($row['book_title']); ?>
                        </div>
                        <div class="fine-meta">
                            <span>Book ID: <?php echo htmlspecialchars($row['book_uid']); ?></span> <br>
                            <span>Type: <?php echo htmlspecialchars($row['fine_type']); ?></span> &bull; 
                            <span>Date: <?php echo date('M d, Y', strtotime($row['fine_date'])); ?></span> &bull;
                            <span><i class="fas fa-university"></i> <?php echo htmlspecialchars($row['library_name'] ?? 'General'); ?></span>
                        </div>
                    </div>

                    <div class="fine-status-box">
                        <div class="fine-amount">
                            <?php echo $currency . number_format($row['fine_amount'], 2); ?>
                        </div>
                        
                        <?php if ($row['payment_status'] === 'Paid'): ?>
                            <span class="status-pill st-paid"><i class="fas fa-check-circle"></i> Paid</span>
                            <a href="generate_receipt.php?fine_id=<?php echo $row['fine_id']; ?>" target="_blank" class="btn-print" style="margin-top: 5px;">
                                <i class="fas fa-print"></i> Receipt
                            </a>
                        <?php else: ?>
                            <span class="status-pill st-pending"><i class="fas fa-exclamation-circle"></i> Pending</span>
                            <?php if ($row['pay_later_due_date']): ?>
                                <div class="pay-later-info">
                                    Due: <?php echo date('M d, Y', strtotime($row['pay_later_due_date'])); ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-smile-beam"></i>
                <h2>No Fines!</h2>
                <p>Great job! You have no fine history.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
user_footer();
close_db_connection($conn);
?>