<?php
require_once '../includes/functions.php';
require_admin_login();

global $conn;

// Receive Data
$uid = $_POST['member_uid'] ?? '';
$name = $_POST['member_name'] ?? '';
$dept = $_POST['member_dept'] ?? '';
$type = $_POST['label_type'] ?? 'qrcode';
$medium = $_POST['print_medium'] ?? 'card';
$layout = $_POST['print_layout'] ?? 'landscape'; // Capture Layout
$copies = (int)($_POST['copy_count'] ?? 1);
$photo_opt = $_POST['photo_option'] ?? 'without';

if (empty($uid)) die("No member selected.");

// --- Handle Photo Upload (Temporary Processing) ---
$photo_base64 = '';
if ($photo_opt === 'with' && isset($_FILES['member_photo']) && $_FILES['member_photo']['error'] === UPLOAD_ERR_OK) {
    $tmp_name = $_FILES['member_photo']['tmp_name'];
    // Verify image type
    $check = getimagesize($tmp_name);
    if ($check !== false) {
        $type_img = pathinfo($_FILES['member_photo']['name'], PATHINFO_EXTENSION);
        $data = file_get_contents($tmp_name);
        $photo_base64 = 'data:image/' . $type_img . ';base64,' . base64_encode($data);
    }
}

// Fetch Branding
$inst_name = get_setting($conn, 'institution_name') ?: 'Institution';
$logo_path_raw = get_setting($conn, 'institution_logo');
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
            background: #f3f4f6; /* Screen background */
            margin: 0;
            padding: 40px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* --- Base Card Styling --- */
        .label-card {
            border: 1px solid #e5e7eb;
            border-radius: 3mm;
            padding: 3mm;
            box-sizing: border-box;
            background: white;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column; 
            z-index: 1;
        }

        /* Watermark */
        .label-watermark {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 60%; height: 60%; 
            z-index: 0;
            opacity: 0.08; 
            pointer-events: none;
            display: flex; align-items: center; justify-content: center;
        }
        .label-watermark img { width: 100%; height: auto; object-fit: contain; filter: grayscale(100%); }

        /* --- Content Sections --- */
        .header-row {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; position: relative; z-index: 2; margin-bottom: 2mm;
        }
        .header-logo { height: 30px; width: 30px; object-fit: contain; margin-bottom: 2px; }
        .inst-name {
            font-size: 11px; text-transform: uppercase; color: #6b7280; font-weight: 700;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; line-height: 1;
        }
        .card-type-badge { 
            font-size: 10px; color: white; background: #4f46e5; 
            padding: 2px 8px; border-radius: 10px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; 
            display: inline-block; margin-top: 5px;
        }

        /* Main Body Layout */
        .main-body {
            display: flex;
            flex: 1;
            position: relative;
            z-index: 2;
            /* Landscape Default: Row */
            flex-direction: row;
            align-items: center;
            justify-content: space-between;
            gap: 3mm;
        }

        /* Portrait specific override for main body direction */
        .label-card.portrait .main-body {
            flex-direction: column;
            justify-content: space-around;
        }

        /* Photo Column */
        .photo-col {
            flex-shrink: 0;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .photo-box {
            border: 1px dashed #9ca3af; background: #f9fafb;
            display: flex; align-items: center; justify-content: center; border-radius: 2px;
            width: 22mm; height: 28mm;
        }
        .photo-box span { font-size: 6px; color: #9ca3af; font-weight: 600; }
        .uploaded-photo { width: 22mm; height: 28mm; object-fit: cover; border-radius: 2px; border: 1px solid #e5e7eb; }

        /* Data Column (Holds Info + QR) */
        .data-col {
            flex-grow: 1;
            display: flex;
            flex-direction: row; /* Force side-by-side for Info and QR */
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }
        
        /* If barcode (which takes up width), stack it */
        .data-col.barcode-mode {
            flex-direction: column;
            justify-content: center;
            text-align: center;
        }

        /* Text Info Section */
        .text-info {
            display: flex; flex-direction: column; justify-content: center;
            overflow: hidden;
            flex: 1; /* Take available space */
        }
        
        /* Ensure text alignment */
        .data-col.barcode-mode .text-info { text-align: center; margin-bottom: 2mm; }
        .label-card.landscape .text-info { text-align: left; }
        .label-card.portrait .text-info { text-align: left; } /* Even in portrait, align text left next to QR */

        .member-name {
            font-size: 12px; font-weight: 700; color: #111827; line-height: 1.1; margin-bottom: 2px;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .member-dept { font-size: 10px; color: #374151; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .member-uid-text {
            font-family: monospace; font-size: 11px; font-weight: 600; color: #4f46e5;
            background: rgba(238, 242, 255, 0.8); padding: 1px 4px; border-radius: 3px;
            display: inline-block;
        }

        /* QR Code Box */
        .qr-code-box {
            flex-shrink: 0;
            margin-left: 2mm;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Barcode Wrapper */
        .barcode-wrapper { margin-top: 2px; width: 100%; overflow: hidden; }
        .barcode-wrapper svg { max-width: 100%; height: auto; }

        /* --- DIMENSIONS --- */
        /* Landscape: 85.6mm x 54mm */
        .label-card.landscape { width: 85.6mm; height: 54mm; }
        
        /* Portrait: 54mm x 85.6mm */
        .label-card.portrait { width: 54mm; height: 85.6mm; }
        
        /* Portrait Tweaks for "Beside" Layout */
        /* In portrait, main-body is column. We want data-col to be a row (Info + QR) */
        .label-card.portrait .photo-col { margin-bottom: 3mm; }
        .label-card.portrait .data-col { 
            width: 100%; 
            justify-content: space-between; 
        }

        /* Action Bar */
        .action-bar { margin-bottom: 20px; display: flex; gap: 10px; }
        .btn { padding: 10px 20px; border-radius: 6px; border: none; font-weight: 600; cursor: pointer; color: white; }
        .btn-print { background: #111827; }
        .btn-close { background: #ef4444; }

        /* --- PRINT SETTINGS --- */
        @media print {
            body { padding: 0; margin: 0; background: none; }
            .action-bar { display: none !important; }
            .label-watermark { opacity: 0.1 !important; }
            * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            
            <?php if ($medium === 'card'): ?>
                @page { size: <?php echo ($layout === 'portrait') ? '54mm 85.6mm' : '85.6mm 54mm'; ?>; margin: 0; }
                .print-container { width: 100%; height: 100%; }
                .label-card { border: none; border-radius: 0; page-break-after: always; margin: 0; }
            <?php else: ?>
                @page { size: A4 <?php echo $layout; ?>; margin: 10mm; }
                .print-container { display: flex; flex-wrap: wrap; gap: 5mm; align-content: flex-start; }
                .label-card { border: 1px dashed #9ca3af; page-break-inside: avoid; }
            <?php endif; ?>
        }

        /* Screen Preview Container */
        <?php if ($medium !== 'card'): ?>
        .print-container {
            width: <?php echo ($layout === 'portrait') ? '297mm' : '210mm'; ?>;
            min-height: <?php echo ($layout === 'portrait') ? '210mm' : '297mm'; ?>;
            background: white; padding: 10mm;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            display: flex; flex-wrap: wrap; gap: 5mm; align-content: flex-start;
        }
        <?php else: ?>
        .print-container { display: flex; flex-direction: column; gap: 10px; }
        <?php endif; ?>

    </style>
</head>
<body>

    <div class="action-bar no-print">
        <button class="btn btn-print" onclick="window.print()">Print Labels</button>
        <button class="btn btn-close" onclick="window.close()">Close</button>
    </div>

    <div class="print-container">
        <?php for($i = 0; $i < $copies; $i++): 
            $code_id = "code_target_" . $i;
        ?>
        <div class="label-card <?php echo $layout; ?>">
            <?php if ($logo_path): ?>
                <div class="label-watermark"><img src="<?php echo $logo_path; ?>" alt=""></div>
            <?php endif; ?>

            <div class="header-row">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo" class="header-logo">
                <?php endif; ?>
                <div class="inst-name"><?php echo htmlspecialchars($inst_name); ?></div>
                <div class="card-type-badge">Library Card</div>
            </div>

            <div class="main-body">
                <div class="photo-col">
                    <?php if (!empty($photo_base64)): ?>
                        <img src="<?php echo $photo_base64; ?>" class="uploaded-photo">
                    <?php else: ?>
                        <div class="photo-box"><span>PHOTO</span></div>
                    <?php endif; ?>
                </div>

                <div class="data-col <?php echo ($type === 'barcode') ? 'barcode-mode' : ''; ?>">
                    <div class="text-info">
                        <div class="member-name"><?php echo htmlspecialchars($name); ?></div>
                        <div class="member-dept"><?php echo htmlspecialchars($dept); ?></div>
                        <div class="member-uid-text"><?php echo htmlspecialchars($uid); ?></div>
                        
                        <?php if ($type === 'barcode'): ?>
                            <div class="barcode-wrapper">
                                <svg id="<?php echo $code_id; ?>"></svg>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($type === 'qrcode'): ?>
                        <div class="qr-code-box" id="<?php echo $code_id; ?>"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <script>
        const uid = "<?php echo htmlspecialchars($uid); ?>";
        const type = "<?php echo $type; ?>";
        const copies = <?php echo $copies; ?>;
        const layout = "<?php echo $layout; ?>";

        // Determine size based on layout
        // In portrait, with "beside" layout, space is tight, reduce QR size slightly.
        const qrSize = (layout === 'portrait') ? 45 : 55; 

        for (let i = 0; i < copies; i++) {
            let elementId = "code_target_" + i;
            
            if (type === 'barcode') {
                JsBarcode("#" + elementId, uid, {
                    format: "CODE128",
                    width: 1,
                    height: 25,
                    displayValue: false,
                    margin: 0,
                    background: "transparent"
                });
            } else {
                const qrContainer = document.getElementById(elementId);
                if (qrContainer) {
                    new QRCode(qrContainer, {
                        text: uid,
                        width: qrSize, 
                        height: qrSize,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.M
                    });
                }
            }
        }
    </script>

</body>
</html>
<?php close_db_connection($conn); ?>
