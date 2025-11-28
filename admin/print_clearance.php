<?php
// File: lms_project_2/admin/print_clearance.php

require_once '../includes/functions.php';
require_admin_login();
global $conn;

// --- Fetch Branding Details ---
$inst_name = get_setting($conn, 'institution_name');
$lib_name = get_setting($conn, 'library_name');
$logo_path_raw = get_setting($conn, 'institution_logo');
$logo_path = (!empty($logo_path_raw) && file_exists('../' . $logo_path_raw)) ? '../' . $logo_path_raw : '';

// --- Get Data from URL Parameters ---
$member = [
    'full_name' => htmlspecialchars($_GET['full_name'] ?? 'N/A'),
    'member_uid' => htmlspecialchars($_GET['member_uid'] ?? 'N/A'),
    'department' => htmlspecialchars($_GET['department'] ?? 'N/A'),
    'email' => htmlspecialchars($_GET['email'] ?? 'N/A'),
];
$generated_by = htmlspecialchars($_GET['generated_by'] ?? 'System');
$timestamp = htmlspecialchars($_GET['timestamp'] ?? date('Y-m-d\TH:i:s'));
$formatted_date = date('M d, Y H:i:s', strtotime($timestamp));

// Function to render certificate HTML
function render_certificate($member, $formatted_date, $generated_by, $inst_name, $lib_name, $logo_path) {
    ?>
    <div class="clearance-document">
        
        <div class="watermark-logo">
            <?php if ($logo_path): ?>
                <img src="<?php echo $logo_path; ?>" alt="Watermark">
            <?php endif; ?>
        </div>

        <div class="watermark-pattern">
            <?php 
            // Repeat the institution name to create a pattern
            for($i = 0; $i < 60; $i++) {
                echo '<span>' . htmlspecialchars($inst_name) . '</span>';
            }
            ?>
        </div>

        <div class="clearance-header">
            <div style="display: flex; align-items: center; justify-content: center; gap: 15px;">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-book-open fa-2x" style="color: var(--primary-color);"></i>
                <?php endif; ?>
                <div style="text-align: left;">
                    <h2><?php echo htmlspecialchars($inst_name); ?></h2>
                    <p><?php echo htmlspecialchars($lib_name); ?></p>
                </div>
            </div>
            <h3>LIBRARY CLEARANCE CERTIFICATE</h3>
        </div>

        <div class="content-row">
            <div class="info-column">
                <h4>Member Details</h4>
                <div class="info-item"><span class="label">Name:</span> <span class="value"><?php echo $member['full_name']; ?></span></div>
                <div class="info-item"><span class="label">ID:</span> <span class="value"><?php echo $member['member_uid']; ?></span></div>
                <div class="info-item"><span class="label">Dept:</span> <span class="value"><?php echo $member['department']; ?></span></div>
            </div>
            
            <div class="info-column">
                <h4>Clearance Info</h4>
                <div class="info-item"><span class="label">Status:</span> <span class="value" style="color: var(--success-color);">CLEARED</span></div>
                <div class="info-item"><span class="label">Date:</span> <span class="value"><?php echo $formatted_date; ?></span></div>
                <div class="info-item"><span class="label">By:</span> <span class="value"><?php echo $generated_by; ?></span></div>
            </div>
        </div>

        <div class="clearance-status-msg">
            <i class="fas fa-check-circle"></i> This member has no outstanding dues, issued books, or active reservations.
        </div>

        <div class="clearance-footer">
            <div class="signature-box">
                <div class="line"></div>
                <div class="text">Member Signature</div>
            </div>
            <div class="signature-box">
                <div class="line"></div>
                <div class="text">Librarian Signature</div>
            </div>
        </div>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Library Clearance - <?php echo $member['member_uid']; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4f46e5;
            --success-color: #059669;
            --success-bg: #d1fae5;
            --text-dark: #111827;
            --text-muted: #6b7280;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: #e5e7eb; /* Grey background for screen preview */
            margin: 0;
            padding: 20px;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        /* The A4 Sheet Container (Visual only for screen) */
        .print-sheet {
            background: white;
            width: 210mm;
            min-height: 150mm; 
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Individual Certificate */
        .clearance-document {
            width: 210mm;
            height: 150mm;
            padding: 25px 40px;
            box-sizing: border-box;
            position: relative;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 3px double var(--primary-color); 
            overflow: hidden; /* Contain the watermarks */
            z-index: 1;
        }

        /* --- 1. CENTRAL LOGO WATERMARK --- */
        .watermark-logo {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 0; /* Behind content and text pattern */
            opacity: 0.08; /* Very faint */
            pointer-events: none;
        }
        .watermark-logo img {
            width: 350px; /* Bigger size as requested */
            height: auto;
            filter: grayscale(100%);
        }

        /* --- 2. TEXT PATTERN WATERMARK --- */
        .watermark-pattern {
            position: absolute;
            top: -20%; 
            left: -20%; 
            width: 140%; 
            height: 140%;
            z-index: 0; /* Behind main content */
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            opacity: 0.05; /* Even fainter than logo */
            pointer-events: none;
            transform: rotate(-10deg);
        }

        .watermark-pattern span {
            flex: 0 0 auto;
            font-size: 12px;
            color: #000;
            padding: 20px 40px;
            /* text-transform: lowercase;  REMOVED: Show name as is */
            font-weight: 700;
            white-space: nowrap;
            user-select: none;
        }

        /* Ensure Main Content sits above watermarks */
        .clearance-header, .content-row, .clearance-status-msg, .clearance-footer {
            position: relative;
            z-index: 2;
        }

        /* Header */
        .clearance-header { text-align: center; margin-bottom: 10px; }
        .clearance-header img { height: 50px; }
        .clearance-header h2 { font-size: 1.4rem; margin: 0; color: var(--text-dark); line-height: 1.2; }
        .clearance-header p { font-size: 0.9rem; color: var(--text-muted); margin: 0; }
        .clearance-header h3 { 
            font-size: 1.2rem; 
            color: var(--success-color); 
            margin: 10px 0 0 0; 
            text-transform: uppercase; 
            letter-spacing: 1px;
            border-bottom: 2px solid var(--success-color);
            display: inline-block;
            padding-bottom: 2px;
        }

        /* Content Layout */
        .content-row {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            gap: 20px;
        }
        .info-column {
            flex: 1;
            /* Semi-transparent white to make text readable over watermark */
            background: rgba(255, 255, 255, 0.7); 
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #f3f4f6;
        }
        .info-column h4 {
            margin: 0 0 10px 0;
            font-size: 0.9rem;
            color: var(--primary-color);
            text-transform: uppercase;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 5px;
        }
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        .info-item .label { color: var(--text-muted); font-size: 0.85rem; }
        .info-item .value { font-weight: 700; color: var(--text-dark); }

        .clearance-status-msg {
            text-align: center;
            font-size: 0.9rem;
            color: var(--success-color);
            background: rgba(236, 253, 245, 0.85); /* Semi-transparent */
            padding: 8px;
            border-radius: 6px;
            margin: 10px 0;
            border: 1px solid #d1fae5;
        }

        /* Footer */
        .clearance-footer {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            padding: 0 20px;
        }
        .signature-box {
            text-align: center;
            width: 180px;
        }
        .signature-box .line {
            border-top: 1px solid #333;
            margin-bottom: 5px;
            height: 30px;
            margin-top: 20px;
        }
        .signature-box .text {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        /* Buttons */
        .action-bar {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.2s;
        }
        .btn:hover { opacity: 0.9; }
        .btn-print { background: var(--text-dark); color: white; }
        .btn-close { background: #e5e7eb; color: var(--text-dark); }

        /* PRINT MEDIA QUERY */
        @media print {
            @page {
                size: 210mm 150mm landscape; 
                margin: 10mm;
            }
            body { 
                background: none; 
                padding: 0; 
                margin: 0; 
                display: block; 
                width: 210mm; 
                height: 150mm; 
                box-sizing: border-box; 
                overflow: hidden; 
            }
            .print-sheet {
                box-shadow: none;
                width: 100%;
                height: auto;
            }
            .clearance-document {
                border: 3px double var(--primary-color);
                width: 210mm;
                height: 150mm;
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact;
            }
            .no-print { display: none !important; }
            
            /* Ensure watermarks print */
            .watermark-logo { opacity: 0.1 !important; }
            .watermark-pattern { opacity: 0.05 !important; }
        }
    </style>
</head>
<body>

<div class="print-sheet">
    <?php render_certificate($member, $formatted_date, $generated_by, $inst_name, $lib_name, $logo_path); ?>
</div>

<div class="action-bar no-print">
    <button class="btn btn-print" onclick="window.print()">
        <i class="fas fa-print"></i> Print Page
    </button>
    <button class="btn btn-close" onclick="window.close()">
        <i class="fas fa-times"></i> Close
    </button>
</div>

</body>
</html>
<?php close_db_connection($conn); ?>