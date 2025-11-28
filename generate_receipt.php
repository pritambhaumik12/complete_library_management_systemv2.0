<?php
// File: lms_project_2/generate_receipt.php

require_once 'includes/functions.php';
require_member_login(); // Only requires a member session
global $conn;

$fine_id = (int)($_GET['fine_id'] ?? 0);
$member_id = $_SESSION['member_id']; // Get logged-in member ID
$currency = get_setting($conn, 'currency_symbol');

if ($fine_id === 0) {
    die("Invalid Fine ID.");
}

// Fetch fine and related details, filtered by fine_id AND member_id for security
// Added l.library_name and l.library_initials to the SELECT
$sql = "
    SELECT 
        tf.fine_id, tf.fine_amount, tf.paid_on, tf.payment_method, tf.transaction_id, tf.fine_date, tf.fine_type,
        tm.full_name AS member_name, tm.member_uid,
        tb.title AS book_title,
        tbc.book_uid,
        ta.full_name AS collected_by_admin,
        tc.issue_date, tc.due_date,
        l.library_name, l.library_initials
    FROM 
        tbl_fines tf
    JOIN 
        tbl_members tm ON tf.member_id = tm.member_id
    JOIN 
        tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    JOIN 
        tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    JOIN 
        tbl_books tb ON tbc.book_id = tb.book_id
    LEFT JOIN
        tbl_libraries l ON tb.library_id = l.library_id
    LEFT JOIN
        tbl_admin ta ON tf.collected_by_admin_id = ta.admin_id
    WHERE 
        tf.fine_id = ? AND tf.payment_status = 'Paid' AND tf.member_id = ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $fine_id, $member_id); // Filter by both Fine ID and Member ID
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Fine record not found, not yet paid, or access denied.");
}

$fine_data = $result->fetch_assoc();
$inst_name = get_setting($conn, 'institution_name');

// Use specific library name if available (from the book's library), otherwise fallback to global setting
$lib_name = !empty($fine_data['library_name']) ? $fine_data['library_name'] : get_setting($conn, 'library_name');

// --- Generate Formatted Receipt ID ---
$inst_init = get_setting($conn, 'institution_initials') ?: 'INS';
// Use specific library initials if available, otherwise fallback to global setting
$lib_init = !empty($fine_data['library_initials']) ? $fine_data['library_initials'] : (get_setting($conn, 'library_initials') ?: 'LIB');

// --- UPDATED FORMAT: {INST}/{LIB}/FINE/{ID} ---
$formatted_fine_id = strtoupper("$inst_init/$lib_init/FINE/$fine_id");

// --- Get Logo ---
$logo_path = get_setting($conn, 'institution_logo');
$logo_html = '';
// Path check adjusted for root directory
if (!empty($logo_path) && file_exists($logo_path)) {
    $logo_html = '<img src="' . $logo_path . '" class="receipt-logo">';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?php echo $formatted_fine_id; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2563eb;
            --success-color: #10b981;
            --success-bg: #d1fae5;
            --text-dark: #1f2937;
            --text-light: #6b7280;
            --bg-page: #f3f4f6;
            --bg-card: #ffffff;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-page);
            margin: 0;
            padding: 40px 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        /* --- Card Design --- */
        .receipt-card {
            background: var(--bg-card);
            width: 100%;
            max-width: 380px; 
            padding: 0;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            position: relative;
        }

        /* Top Decoration Bar */
        .brand-stripe {
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), #60a5fa);
            width: 100%;
        }

        .receipt-content {
            padding: 25px;
        }

        /* --- Header --- */
        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .receipt-logo {
            height: 50px;
            margin-bottom: 10px;
            object-fit: contain;
        }

        .inst-name {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            line-height: 1.2;
        }

        .lib-name {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 4px;
        }

        .receipt-badge {
            display: inline-block;
            background: #eff6ff;
            color: var(--primary-color);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* --- Info Sections --- */
        .divider {
            border-top: 1px dashed #e5e7eb;
            margin: 15px 0;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .info-row-a {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .label {
            color: var(--text-light);
            font-weight: 500;
        }

        .value {
            color: var(--text-dark);
            font-weight: 600;
            text-align: right;
            max-width: 65%;
            word-wrap: break-word;
        }

        .section-title {
            font-size: 11px;
            text-transform: uppercase;
            color: #9ca3af;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        /* --- Total Box --- */
        .total-box {
            background-color: var(--success-bg);
            border: 1px solid var(--success-color);
            border-radius: 8px;
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            color: #065f46;
        }

        .total-label {
            font-size: 13px;
            font-weight: 600;
        }

        .total-amount {
            font-size: 18px;
            font-weight: 800;
        }

        /* --- Paid Stamp --- */
        .paid-stamp {
            position: absolute;
            top: 140px;
            right: 30px;
            border: 3px solid #ef4444; 
            color: #ef4444;
            font-size: 24px;
            font-weight: 900;
            text-transform: uppercase;
            padding: 5px 15px;
            transform: rotate(-15deg);
            opacity: 0.15;
            border-radius: 6px;
            pointer-events: none;
            z-index: 0;
        }

        /* --- Footer --- */
        .footer {
            text-align: center;
            font-size: 11px;
            color: #9ca3af;
            margin-top: 20px;
        }

        /* --- Actions --- */
        .actions {
            margin-top: 25px;
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-print {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
        }
        .btn-print:hover { background-color: #1d4ed8; }

        .btn-close {
            background-color: white;
            color: var(--text-dark);
            border: 1px solid #d1d5db;
        }
        .btn-close:hover { background-color: #f9fafb; }

        /* --- Print Styles --- */
        @media print {
            body {
                background: none;
                padding: 0;
                margin: 0;
                display: block;
            }
            .receipt-card {
                box-shadow: none;
                border: 1px solid #000;
                max-width: 100%;
                width: 300px; /* Force narrow width for thermal printers */
                margin: 0;
                border-radius: 0;
            }
            .brand-stripe, .actions {
                display: none;
            }
            .paid-stamp {
                opacity: 0.1; 
                border-color: #000;
                color: #000;
            }
            .total-box {
                background: none;
                border: 1px solid #000;
                color: #000;
            }
            .receipt-badge {
                border: 1px solid #ccc;
                color: #000;
                background: none;
            }
        }
    </style>
</head>
<body>

<div class="receipt-card">
    <div class="brand-stripe"></div>
    
    <div class="paid-stamp">PAID</div>

    <div class="receipt-content">
        <div class="header">
            <?php echo $logo_html; ?>
            <h1 class="inst-name"><?php echo htmlspecialchars($inst_name); ?></h1>
            <p class="lib-name"><?php echo htmlspecialchars($lib_name); ?></p>
            <span class="receipt-badge">Fine Receipt</span>
        </div>

        <div class="info-row">
            <span class="label">Receipt ID</span>
            <span class="value"><?php echo $formatted_fine_id; ?></span>
        </div>
        <div class="info-row">
            <span class="label">Date</span>
            <span class="value"><?php echo date('d-M-Y H:i', strtotime($fine_data['paid_on'])); ?></span>
        </div>

        <div class="divider"></div>

        <div class="section-title">Member Info</div>
        <div class="info-row">
            <span class="label">Name</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['member_name']); ?></span>
        </div>
        <div class="info-row">
            <span class="label">User ID</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['member_uid']); ?></span>
        </div>

        <div class="divider"></div>

        <div class="section-title">Fine Details</div>
        <div class="info-row">
            <span class="label">Reason</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['fine_type']); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Book</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['book_title']); ?></span>
        </div>
        <div class="info-row">
            <span class="label">Book UID</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['book_uid']); ?></span>
        </div>
        
        <?php if ($fine_data['fine_type'] === 'Late Return'): ?>
            <div class="info-row">
                <span class="label">Due Date</span>
                <span class="value"><?php echo date('d-M-Y', strtotime($fine_data['due_date'])); ?></span>
            </div>
            <div class="info-row">
                <span class="label">Returned</span>
                <span class="value"><?php echo date('d-M-Y', strtotime($fine_data['fine_date'])); ?></span>
            </div>
            <?php 
                $overdue_days = max(0, floor((strtotime($fine_data['fine_date']) - strtotime($fine_data['due_date'])) / (60 * 60 * 24)));
            ?>
            <div class="info-row">
                <span class="label">Overdue</span>
                <span class="value"><?php echo $overdue_days; ?> Days</span>
            </div>
        <?php endif; ?>

        <div class="divider"></div>

        <div class="section-title">Payment Info</div>
        <div class="info-row">
            <span class="label">Method</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['payment_method']); ?></span>
        </div>

        <div class="info-row-a">
            <span class="label">Cashier:</span>
            <span class="value"><?php echo htmlspecialchars($fine_data['collected_by_admin'] ?? 'System'); ?></span>
        </div>



        <?php if ($fine_data['transaction_id']): ?>
            <div class="info-row">
                <span class="label">Trans ID</span>
                <span class="value"><?php echo htmlspecialchars($fine_data['transaction_id']); ?></span>
            </div>
        <?php endif; ?>

        <div class="total-box">
            <span class="total-label">TOTAL PAID</span>
            <span class="total-amount"><?php echo $currency . ' ' . number_format($fine_data['fine_amount'], 2); ?></span>
        </div>

        <div class="footer">
            <p style="margin-top:5px;">This is a computer-generated receipt.</p>
        </div>
    </div>
</div>

<div class="actions">
    <button class="btn btn-close" onclick="window.close()">Close</button>
    <button class="btn btn-print" onclick="window.print()">Print Receipt</button>
</div>

</body>
</html>