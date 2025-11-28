<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

// Receive Data
$uid = $_POST['member_uid'] ?? '';
$name = $_POST['member_name'] ?? '';
$dept = $_POST['member_dept'] ?? '';
$type = $_POST['label_type'] ?? 'qrcode';

if (empty($uid)) die("No member selected.");

// Fetch Institution Name
$inst_name = get_setting($conn, 'institution_name') ?: 'Institution';

// Fetch Institution Logo
$logo_path_raw = get_setting($conn, 'institution_logo');
// Ensure path is relative to admin folder (go up one level)
$logo_path = (!empty($logo_path_raw) && file_exists('../' . $logo_path_raw)) ? '../' . $logo_path_raw : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Label - <?php echo htmlspecialchars($name); ?></title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            background: #f3f4f6;
            margin: 0;
            padding: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Print Settings */
        @media print {
            body { background: none; padding: 0; margin: 0; }
            .no-print { display: none !important; }
            .label-card { 
                border: 1px solid #ddd; /* Light border for cutting guide */
                box-shadow: none !important;
                page-break-inside: avoid;
            }
            .print-container { margin: 0; }
            
            /* Ensure watermark prints */
            .label-watermark { opacity: 0.1 !important; }
            .label-card { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }

        .print-container {
            background: white;
            padding: 10mm;
            width: 210mm; 
            min-height: 297mm;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            display: flex;
            flex-wrap: wrap;
            gap: 10mm;
            align-content: flex-start;
        }

        /* --- Label Card Layout (ID-1 Size) --- */
        .label-card {
            width: 85.6mm;  /* ID-1 Width */
            height: 54mm;   /* ID-1 Height */
            border: 1px solid #e5e7eb;
            border-radius: 3mm;
            /* Padding: Top Right Bottom Left */
            padding: 3mm 3mm 2mm 6mm; 
            box-sizing: border-box;
            background: white;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column; 
            justify-content: flex-start;
            z-index: 1; /* Ensure content is above watermark */
        }

        /* Decoration Stripe */
        .label-card::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0;
            width: 4mm; background: #4f46e5;
            z-index: 2; /* Stripe above watermark */
        }

        /* --- Watermark Style --- */
        .label-watermark {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 60%;
            height: 60%;
            z-index: 0; /* Behind everything */
            opacity: 0.08; /* Very faint on screen */
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .label-watermark img {
            width: 100%; height: auto; object-fit: contain; filter: grayscale(100%);
        }

        /* --- 1. Header Row (Logo + Inst Name + Title Centered) --- */
        .header-row {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            margin-bottom: 2mm;
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .header-logo {
            height: 35px; 
            width: 35px; 
            object-fit: contain; 
            margin-bottom: 2px;
        }

        .inst-name {
            font-size: 14px; text-transform: uppercase; color: #6b7280;
            font-weight: 700; letter-spacing: 0.5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            max-width: 100%; line-height: 1;
        }
        
        .card-title {
            font-size:11px; 
            text-transform: uppercase; 
            color: #4f46e5;
            font-weight: 600; 
            letter-spacing: 1px;
            margin-top: 1px;
        }

        /* --- 2. Main Content Row (Photo - Info - QR) --- */
        .main-row {
            display: flex;
            flex-direction: row;
            align-items: center;
            width: 100%;
            flex: 1; 
            position: relative;
            z-index: 2;
        }

        /* Left: Photo Box */
        .photo-section {
            flex-shrink: 0;
            margin-right: 3mm;
        }
        
        .photo-box {
            width: 20mm;
            height: 25mm;
            border: 1px dashed #9ca3af;
            background-color: rgba(249, 250, 251, 0.8); /* Semi-transparent background */
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 2px;
        }
        .photo-box span {
            font-size: 6px;
            color: #9ca3af;
            font-weight: 600;
            transform: rotate(-45deg);
        }

        /* Middle: Info (Centered Text) */
        .info-section {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center; 
            text-align: center;  
            overflow: hidden;
            padding-right: 2mm;
        }
        
        .member-name {
            font-size: 15px; font-weight: 700; color: #111827;
            line-height: 1.1; margin-bottom: 2px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        
        .member-dept { font-size: 12px; color: #374151; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .member-uid-text {
            font-family: monospace; font-size: 13px; font-weight: 600;
            color: #4f46e5; background: rgba(238, 242, 255, 0.8); /* Semi-transparent */
            padding: 1px 4px; border-radius: 3px; width: fit-content;
        }

        .barcode-wrapper {
            margin-top: 3px; /* Spacing from ID */
            text-align: center;
        }

        /* Right: QR Code Area */
        .qr-section {
            flex-shrink: 0;
            width: 18mm;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        /* Action Bar */
        .action-bar { margin-bottom: 20px; display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border-radius: 6px; border: none; font-weight: 600; cursor: pointer; color: white; }
        .btn-print { background: #111827; }
        .btn-close { background: #ef4444; }

    </style>
</head>
<body>

    <div class="action-bar no-print">
        <button class="btn btn-print" onclick="window.print()">Print Label</button>
        <button class="btn btn-close" onclick="window.close()">Close</button>
    </div>

    <div class="print-container">
        
        <div class="label-card">
            
            <?php if ($logo_path): ?>
                <div class="label-watermark">
                    <img src="<?php echo $logo_path; ?>" alt="">
                </div>
            <?php endif; ?>

            <div class="header-row">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo" class="header-logo">
                <?php endif; ?>
                <div class="inst-name"><?php echo htmlspecialchars($inst_name); ?></div>
                <div class="card-title">Library Member ID Card</div>
            </div>

            <div class="main-row">
                
                <div class="photo-section">
                    <div class="photo-box"><span>PHOTO</span></div>
                </div>

                <div class="info-section">
                    <div class="member-name"><?php echo htmlspecialchars($name); ?></div>
                    <div class="member-dept"><?php echo htmlspecialchars($dept); ?></div>
                    <div class="member-uid-text"><?php echo htmlspecialchars($uid); ?></div>
                    
                    <?php if ($type === 'barcode'): ?>
                        <div class="barcode-wrapper">
                            <svg id="barcode-target"></svg>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($type !== 'barcode'): ?>
                    <div class="qr-section" id="qr-target"></div>
                <?php endif; ?>
            </div>

        </div>

    </div>

    <script>
        const uid = "<?php echo htmlspecialchars($uid); ?>";
        const type = "<?php echo $type; ?>";

        if (type === 'barcode') {
            JsBarcode("#barcode-target", uid, {
                format: "CODE128",
                width: 1,       /* Resized: Thinner bars */
                height: 20,     /* Resized: Shorter height */
                displayValue: false, // Text is already displayed in info section
                margin: 0,
                background: "transparent"
            });
        } else {
            const qrContainer = document.getElementById('qr-target');
            new QRCode(qrContainer, {
                text: uid,
                width: 64, 
                height: 64,
                colorDark : "#000000",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        }
    </script>

</body>
</html>
<?php close_db_connection($conn); ?>