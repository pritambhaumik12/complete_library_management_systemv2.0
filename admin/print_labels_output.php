
<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$lib_id = (int)($_POST['library_id'] ?? 0);
$type = $_POST['label_type'] ?? 'qrcode';
$mode = $_POST['mode'] ?? '';

if ($lib_id === 0) die("Invalid Library");

// --- Fetch Book Copies based on mode ---
$copies = [];

// Base SQL joining books to get details
$sql_base = "SELECT tbc.book_uid, tb.title, tb.author, tb.edition, tb.publication 
             FROM tbl_book_copies tbc 
             JOIN tbl_books tb ON tbc.book_id = tb.book_id 
             WHERE tb.library_id = ? AND tbc.book_uid NOT LIKE '%-BASE' AND tb.total_quantity > 0";

if ($mode === 'all') {
    $stmt = $conn->prepare($sql_base . " ORDER BY tb.title ASC, tbc.book_uid ASC");
    $stmt->bind_param("i", $lib_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $copies[] = $row;

} elseif ($mode === 'selected') {
    $selected_ids = $_POST['selected_book_ids'] ?? [];
    if (empty($selected_ids)) die("No books selected.");
    
    // Create ID string for IN clause (Safe since they come from checkbox values, but let's sanitise)
    $ids_sanitized = array_map('intval', $selected_ids);
    $ids_str = implode(',', $ids_sanitized);
    
    $sql = $sql_base . " AND tb.book_id IN ($ids_str) ORDER BY tb.title ASC, tbc.book_uid ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $lib_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $copies[] = $row;

} elseif ($mode === 'one') {
    $book_id = (int)$_POST['one_book_id'];
    $sql = $sql_base . " AND tb.book_id = ? ORDER BY tbc.book_uid ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $lib_id, $book_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while($row = $res->fetch_assoc()) $copies[] = $row;
}

// --- Fetch Branding ---
$inst_name = get_setting($conn, 'institution_name') ?: 'Institution';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Labels</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #eee; }
        .print-container {
            width: 210mm; /* A4 Width */
            background: white;
            margin: 0 auto;
            padding: 10mm;
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* 3 Labels per row */
            gap: 5mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .label-card {
            border: 1px dashed #ccc;
            padding: 10px;
            text-align: center;
            height: 140px; /* Fixed height */
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            page-break-inside: avoid;
            background: #fff;
        }
        
        .label-inst { font-size: 10px; font-weight: bold; text-transform: uppercase; margin-bottom: 2px; }
        .label-title { font-size: 11px; font-weight: bold; margin-bottom: 2px; line-height: 1.1; max-height: 24px; overflow: hidden; }
        .label-meta { font-size: 9px; color: #555; margin-bottom: 5px; }
        .label-uid { font-family: monospace; font-size: 10px; margin-top: 2px; font-weight: bold; }
        
        .code-container { flex-grow: 1; display: flex; align-items: center; justify-content: center; width: 100%; overflow: hidden; }
        
        /* Print Settings */
        @media print {
            body { background: none; margin: 0; padding: 0; }
            .print-container { width: 100%; box-shadow: none; margin: 0; }
            .no-print { display: none; }
            .label-card { border: 1px solid #eee; } /* Light border for cutting guide */
        }
        
        .action-bar { text-align: center; margin-bottom: 20px; }
        .btn { padding: 10px 20px; background: #333; color: white; border: none; cursor: pointer; border-radius: 5px; }
    </style>
</head>
<body>

    <div class="action-bar no-print">
        <button class="btn" onclick="window.print()">Print Labels</button>
        <button class="btn" onclick="window.close()" style="background:#d9534f;">Close</button>
    </div>

    <div class="print-container">
        <?php if (empty($copies)): ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 50px;">No physical copies found matching criteria.</div>
        <?php endif; ?>

        <?php foreach($copies as $index => $copy): 
            $uid = htmlspecialchars($copy['book_uid']);
            $safe_uid = preg_replace('/[^a-zA-Z0-9]/', '_', $uid); // ID safe string
            $element_id = "code_" . $index;
        ?>
            <div class="label-card">
                <div class="label-inst"><?php echo htmlspecialchars($inst_name); ?></div>
                <div class="label-title"><?php echo htmlspecialchars(mb_strimwidth($copy['title'], 0, 40, "...")); ?></div>
                <div class="label-meta">
                    <?php echo htmlspecialchars($copy['author']); ?> 
                    <?php if($copy['edition']) echo " | " . htmlspecialchars($copy['edition']); ?>
                </div>
                
                <div class="code-container" id="container_<?php echo $index; ?>">
                    <?php if ($type === 'barcode'): ?>
                        <svg id="<?php echo $element_id; ?>"></svg>
                    <?php else: ?>
                        <div id="<?php echo $element_id; ?>"></div>
                    <?php endif; ?>
                </div>
                
                <div class="label-uid"><?php echo $uid; ?></div>
            </div>

            <script>
                <?php if ($type === 'barcode'): ?>
                    JsBarcode("#<?php echo $element_id; ?>", "<?php echo $uid; ?>", {
                        format: "CODE128",
                        width: 1.5,
                        height: 40,
                        displayValue: false,
                        margin: 0
                    });
                <?php else: ?>
                    new QRCode(document.getElementById("<?php echo $element_id; ?>"), {
                        text: "<?php echo $uid; ?>",
                        width: 80,
                        height: 80,
                        colorDark : "#000000",
                        colorLight : "#ffffff",
                        correctLevel : QRCode.CorrectLevel.H
                    });
                <?php endif; ?>
            </script>
        <?php endforeach; ?>
    </div>

</body>
</html>
<?php close_db_connection($conn); ?>
