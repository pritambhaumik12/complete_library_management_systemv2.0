<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';

// [NEW] Check for flash message from redirect
if (isset($_SESSION['flash_success'])) {
    $message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$step = $_POST['step'] ?? 1;
$mode = $_POST['mode'] ?? 'direct'; // 'direct' or 'reservation'
$admin_id = $_SESSION['admin_id'];

// --- 0. GET ADMIN LIBRARY CONTEXT ---
$is_super = is_super_admin($conn);
$stmt_admin = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
$stmt_admin->bind_param("i", $admin_id);
$stmt_admin->execute();
$my_lib_id = $stmt_admin->get_result()->fetch_assoc()['library_id'] ?? 0;

// --- Step 2: Validation & Fetching ---
if ($step == 2) {
    // ... (Existing Step 2 logic remains exactly the same) ...
    if ($mode === 'direct') {
        $book_uid = trim($_POST['book_uid'] ?? '');
        $member_uid = trim($_POST['member_uid'] ?? '');

        if (preg_match('/\(([^)]+)\)$/', $member_uid, $matches)) {
            $member_uid = $matches[1];
        }

        if (empty($book_uid) || empty($member_uid)) {
            $error = "Both Book UID and Member ID are required.";
            $step = 1;
        } else {
            $stmt = $conn->prepare("
                SELECT tbc.copy_id, tbc.status, 
                       tb.title, tb.author, tb.category, tb.edition, tb.publication, tb.isbn,
                       tb.book_id, tb.library_id, l.library_name 
                FROM tbl_book_copies tbc 
                JOIN tbl_books tb ON tbc.book_id = tb.book_id 
                LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                WHERE tbc.book_uid = ?
            ");
            $stmt->bind_param("s", $book_uid);
            $stmt->execute();
            $copy_res = $stmt->get_result();

            if ($copy_res->num_rows === 0) {
                $error = "Book Copy ($book_uid) not found."; 
                $step = 1;
            } else {
                $copy_data = $copy_res->fetch_assoc();

                if (!$is_super && $my_lib_id != $copy_data['library_id']) {
                    $lib_name_display = htmlspecialchars($copy_data['library_name'] ?? 'Unassigned');
                    $error = "Access Denied: This book belongs to <strong>$lib_name_display</strong>. You cannot issue books from other libraries.";
                    $step = 1;
                } else {
                    $stmt_mem = $conn->prepare("SELECT member_id, full_name, member_uid FROM tbl_members WHERE member_uid = ? AND status = 'Active'");
                    $stmt_mem->bind_param("s", $member_uid);
                    $stmt_mem->execute();
                    $mem_res = $stmt_mem->get_result();

                    if ($mem_res->num_rows === 0) {
                        $error = "Member not found or inactive."; 
                        $step = 1;
                    } else {
                        $mem_data = $mem_res->fetch_assoc();
                        
                        $stmt_count = $conn->prepare("SELECT COUNT(*) as borrowed_count FROM tbl_circulation WHERE member_id = ? AND status = 'Issued'");
                        $stmt_count->bind_param("i", $mem_data['member_id']);
                        $stmt_count->execute();
                        $borrowed_count = $stmt_count->get_result()->fetch_assoc()['borrowed_count'];

                        $current_book_id = $copy_data['book_id'];
                        $current_member_id = $mem_data['member_id'];
                        $reservation_id_to_fulfill = 0;
                        $has_reservation = false;

                        $stmt_my_res = $conn->prepare("SELECT reservation_id FROM tbl_reservations WHERE book_id = ? AND member_id = ? AND status = 'Accepted' LIMIT 1");
                        $stmt_my_res->bind_param("ii", $current_book_id, $current_member_id);
                        $stmt_my_res->execute();
                        $my_res_result = $stmt_my_res->get_result();
                        
                        if ($my_res_result->num_rows > 0) {
                            $reservation_id_to_fulfill = $my_res_result->fetch_assoc()['reservation_id'];
                            $has_reservation = true;
                        }

                        if ($copy_data['status'] === 'Issued' || $copy_data['status'] === 'Lost') {
                            $error = "Book is currently " . $copy_data['status'];
                            $step = 1;
                        } 
                        elseif ($copy_data['status'] === 'Reserved' && !$has_reservation) {
                            $error = "This specific copy is marked 'Reserved' for another member.";
                            $step = 1;
                        } 
                        else {
                            $block_issue = false;
                            
                            if (!$has_reservation) {
                                $stmt_total_res = $conn->prepare("SELECT COUNT(*) as res_count FROM tbl_reservations WHERE book_id = ? AND status = 'Accepted'");
                                $stmt_total_res->bind_param("i", $current_book_id);
                                $stmt_total_res->execute();
                                $total_res_count = $stmt_total_res->get_result()->fetch_assoc()['res_count'];

                                $stmt_avail = $conn->prepare("SELECT COUNT(*) as avail_qty FROM tbl_book_copies WHERE book_id = ? AND (status = 'Available' OR status = 'Reserved')");
                                $stmt_avail->bind_param("i", $current_book_id);
                                $stmt_avail->execute();
                                $available_qty = $stmt_avail->get_result()->fetch_assoc()['avail_qty'];

                                if ($total_res_count >= $available_qty) {
                                    $block_issue = true;
                                    $error = "Cannot Issue: All available copies are reserved by ($total_res_count) pending requests.";
                                    $step = 1;
                                }
                            }

                            if (!$block_issue) {
                                $issue_data = [
                                    'mode' => 'direct',
                                    'book_uid' => $book_uid,
                                    'title' => $copy_data['title'],
                                    'author' => $copy_data['author'],
                                    'category' => $copy_data['category'],
                                    'edition' => $copy_data['edition'],
                                    'publication' => $copy_data['publication'],
                                    'isbn' => $copy_data['isbn'],
                                    'copy_id' => $copy_data['copy_id'],
                                    'book_id' => $copy_data['book_id'],
                                    'member_name' => $mem_data['full_name'],
                                    'member_uid' => $mem_data['member_uid'],
                                    'member_id' => $mem_data['member_id'],
                                    'borrowed_count' => $borrowed_count,
                                    'reservation_id' => $reservation_id_to_fulfill
                                ];
                                $step = 3;
                            }
                        }
                    }
                }
            }
        }
    } elseif ($mode === 'reservation') {
        // ... (Reservation Fetching Logic remains same) ...
        $reservation_uid = trim($_POST['reservation_uid'] ?? '');
        
        $sql = "
            SELECT tr.reservation_id, tr.book_id, tr.member_id, tr.status, 
                   tb.title, tb.author, tb.category, tb.edition, tb.publication, tb.isbn,
                   tb.library_id, l.library_name, 
                   tm.full_name, tm.member_uid, tm.member_id 
            FROM tbl_reservations tr 
            JOIN tbl_books tb ON tr.book_id = tb.book_id 
            LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
            JOIN tbl_members tm ON tr.member_id = tm.member_id 
            WHERE tr.reservation_uid = ?
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $reservation_uid);
        $stmt->execute();
        $res_result = $stmt->get_result();

        if ($res_result->num_rows === 0) {
            $error = "Reservation ID not found."; $step = 1;
        } else {
            $res_data = $res_result->fetch_assoc();
            
            if (!$is_super && $my_lib_id != $res_data['library_id']) {
                $lib_name_display = htmlspecialchars($res_data['library_name'] ?? 'Unassigned');
                $error = "Access Denied: This reservation belongs to <strong>$lib_name_display</strong>. You cannot issue books from other libraries.";
                $step = 1;
            } else {
                if ($res_data['status'] !== 'Accepted') {
                    $error = "Reservation status is " . $res_data['status'] . ". Only 'Accepted' reservations can be processed."; $step = 1;
                } else {
                    $stmt_count = $conn->prepare("SELECT COUNT(*) as borrowed_count FROM tbl_circulation WHERE member_id = ? AND status = 'Issued'");
                    $stmt_count->bind_param("i", $res_data['member_id']);
                    $stmt_count->execute();
                    $borrowed_count = $stmt_count->get_result()->fetch_assoc()['borrowed_count'];

                    $sql_copies = "SELECT copy_id, book_uid, status FROM tbl_book_copies WHERE book_id = ? AND (status = 'Available' OR status = 'Reserved')";
                    $stmt_copies = $conn->prepare($sql_copies);
                    $stmt_copies->bind_param("i", $res_data['book_id']);
                    $stmt_copies->execute();
                    $copies_result = $stmt_copies->get_result();
                    
                    $available_copies = [];
                    while($row = $copies_result->fetch_assoc()) {
                        $available_copies[] = $row;
                    }

                    if (empty($available_copies)) {
                        $error = "No copies currently available to fulfill this reservation."; $step = 1;
                    } else {
                        $issue_data = [
                            'mode' => 'reservation',
                            'reservation_uid' => $reservation_uid,
                            'reservation_id' => $res_data['reservation_id'],
                            'title' => $res_data['title'],
                            'author' => $res_data['author'],
                            'category' => $res_data['category'],
                            'edition' => $res_data['edition'],
                            'publication' => $res_data['publication'],
                            'isbn' => $res_data['isbn'],
                            'book_id' => $res_data['book_id'],
                            'member_name' => $res_data['full_name'],
                            'member_uid' => $res_data['member_uid'],
                            'member_id' => $res_data['member_id'],
                            'borrowed_count' => $borrowed_count,
                            'available_copies' => $available_copies
                        ];
                        $step = 3;
                    }
                }
            }
        }
    }
}

// --- Step 3: Process Issue ---
if ($step == 3 && isset($_POST['confirm_issue'])) {
    $mode = $_POST['mode'];
    $member_id = $_POST['member_id'];
    $copy_id = $_POST['copy_id'];
    $book_id = $_POST['book_id'];
    $reservation_id = $_POST['reservation_id'];
    
    $stmt = $conn->prepare("SELECT book_uid FROM tbl_book_copies WHERE copy_id = ?");
    $stmt->bind_param("i", $copy_id);
    $stmt->execute();
    $book_uid = $stmt->get_result()->fetch_assoc()['book_uid'];

    $due_days = get_setting($conn, 'max_borrow_days');
    $due_date = date('Y-m-d', strtotime("+$due_days days"));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO tbl_circulation (copy_id, member_id, issue_date, due_date, status, issued_by_admin_id) VALUES (?, ?, NOW(), ?, 'Issued', ?)");
        $stmt->bind_param("iisi", $copy_id, $member_id, $due_date, $admin_id);
        if(!$stmt->execute()) throw new Exception($conn->error);

        $stmt = $conn->prepare("UPDATE tbl_book_copies SET status = 'Issued' WHERE copy_id = ?");
        $stmt->bind_param("i", $copy_id);
        if(!$stmt->execute()) throw new Exception($conn->error);

        $stmt = $conn->prepare("UPDATE tbl_books SET available_quantity = available_quantity - 1 WHERE book_id = ?");
        $stmt->bind_param("i", $book_id);
        if(!$stmt->execute()) throw new Exception($conn->error);

        if ($reservation_id > 0) {
            $stmt = $conn->prepare("UPDATE tbl_reservations SET status = 'Fulfilled' WHERE reservation_id = ?");
            $stmt->bind_param("i", $reservation_id);
            if(!$stmt->execute()) throw new Exception($conn->error);
        }

        $conn->commit();
        // --- START EMAIL LOGIC ---
        // Fetch member email and details
        $stmt_mail = $conn->prepare("SELECT email, full_name FROM tbl_members WHERE member_id = ?");
        $stmt_mail->bind_param("i", $member_id);
        $stmt_mail->execute();
        $mail_data = $stmt_mail->get_result()->fetch_assoc();

        if ($mail_data && !empty($mail_data['email'])) {
            $subject = "Book Issued: " . $book_uid;
            $message = "
                <h3>Book Issued Successfully</h3>
                <p>Dear {$mail_data['full_name']},</p>
                <p>The following book has been issued to your account:</p>
                <ul>
                    <li><strong>Book UID:</strong> {$book_uid}</li>
                    <li><strong>Due Date:</strong> " . date('d M Y', strtotime($due_date)) . "</li>
                </ul>
                <p>Please return it on time to avoid fines.</p>
            ";
            send_system_email($mail_data['email'], $mail_data['full_name'], $subject, $message);
        }
        // --- END EMAIL LOGIC ---


        // [UPDATED] Set session message and redirect (PRG Pattern)
        $_SESSION['flash_success'] = "Book **$book_uid** issued successfully! Due date: " . date('d M Y', strtotime($due_date));
        redirect('issue_book.php');

    } catch (Exception $e) {
        $conn->rollback();
        $error = "Issue failed: " . $e->getMessage();
        $step = 1; // Or handle error redirect if critical
    }
}

admin_header('Issue Book');
?>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<style>
    /* Glass Components */
    .glass-card {
        background: rgba(255, 255, 255, 0.7); /* White transparent background */
        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.7);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.1); /* Darker shadow for depth */
        border-radius: 20px; padding: 30px; margin-bottom: 30px;
        max-width: 800px; margin: 0 auto;
        animation: fadeIn 0.5s ease-out;
        color: #111827; /* Deep Black text */
    }

    .card-header { text-align: center; margin-bottom: 30px; }
    .card-header h2 { margin: 0; color: #000; font-size: 1.8rem; display: flex; align-items: center; justify-content: center; gap: 10px; }
    .card-header p { color: #374151; font-size: 0.9rem; margin-top: 5px; }

    /* Mode Toggle Switch */
    .mode-toggle-container {
        display: flex; background: rgba(255,255,255,0.7); border-radius: 12px; padding: 5px;
        margin-bottom: 30px; border: 1px solid rgba(255,255,255,0.6);
    }
    .mode-btn {
        flex: 1; padding: 12px; border: none; background: transparent;
        font-weight: 700; color: #374151; cursor: pointer; border-radius: 10px; transition: 0.3s;
        display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .mode-btn:hover { background: rgba(243, 244, 246, 0.8); }
    .mode-btn.active { 
        background: linear-gradient(135deg, #4f46e5, #3730a3); 
        color: white; 
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); 
    }

    /* Forms */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: #111827; /* Deep Black label */ margin-bottom: 8px; }
    
    .input-wrapper { position: relative; }
    .input-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #6b7280; }
    
    .form-control {
        width: 90%; padding: 14px 14px 14px 45px;
        border: 1px solid #d1d5db; 
        background: rgba(255, 255, 255, 0.9); /* White transparent */
        border-radius: 12px;
        font-size: 1rem; color: #111827; /* Deep Black input text */ transition: all 0.3s;
    }
    .form-control:focus { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); outline: none; }
    .form-control option { color: #111827; background: #fff; } /* Ensure dropdown text is black */

    /* Verify Grid */
    .verify-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; text-align: left; }
    .info-card { 
        background: rgba(255, 255, 255, 0.85); /* More solid white for data display */
        border: 1px solid #e5e7eb; 
        border-radius: 16px; 
        padding: 20px; 
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .info-card h4 { margin: 0 0 15px 0; color: #4f46e5; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 10px; }
    .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; }
    .info-label { color: #4b5563; }
    .info-val { font-weight: 700; color: #000; /* Deep Black data value */ text-align: right; max-width: 70%; }

    /* Buttons */
    .btn-main {
        background: linear-gradient(135deg, #4f46e5, #3730a3); color: white; border: none;
        padding: 15px; border-radius: 12px; font-weight: 700; font-size: 1rem;
        cursor: pointer; width: 100%; transition: transform 0.2s;
        display: flex; align-items: center; justify-content: center; gap: 10px;
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
    }
    .btn-main:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79, 70, 229, 0.3); }
    
    .btn-confirm { background: #059669; } /* Dark Green */
    .btn-confirm:hover { background: #047857; box-shadow: 0 8px 20px rgba(5, 150, 105, 0.3); }
    
    .btn-cancel { background: #e5e7eb; color: #4b5563; font-weight: 600; box-shadow: none; }
    .btn-cancel:hover { background: #d1d5db; transform: translateY(-2px); }

    /* Scanner Button */
    .btn-scan {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: transparent;
        border: none;
        color: #4f46e5;
        cursor: pointer;
        font-size: 1.2rem;
        padding: 5px;
        z-index: 10;
    }
    .btn-scan:hover { color: #3730a3; transform: translateY(-50%) scale(1.1); }

    /* Alerts */
    .alert { padding: 15px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 10px; justify-content: center; font-weight: 600; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    .alert-warning { background: #fffbeb; padding: 15px; border-radius: 10px; border: 1px solid #fcd34d; color: #92400e; font-size: 0.9rem; } /* Custom warning style */

    /* Modal */
    .modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(17, 24, 39, 0.7); backdrop-filter: blur(5px); justify-content: center; align-items: center; }
    .modal-content { 
        background: #fff; padding: 40px; border-radius: 24px; width: 90%; max-width: 450px; text-align: center; animation: popUp 0.3s ease; 
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    .modal-content h3 { color: #000; }
    
    .modal-actions { margin-top: 25px; display: flex; gap: 10px; justify-content: center; }
    .modal-btn { padding: 12px 24px; border-radius: 10px; border: none; font-weight: 600; cursor: pointer; font-size: 1rem; transition: background 0.2s; }
    .modal-btn:first-child { background: #059669; color: white; }
    .modal-btn:first-child:hover { background: #047857; }
    .modal-btn:last-child { background: #e5e7eb; color: #334155; }
    .modal-btn:last-child:hover { background: #d1d5db; }
    
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

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 768px) { .verify-grid { grid-template-columns: 1fr; } }

    /* --- NEW AUTO-SUGGESTION STYLES --- */
    .autocomplete-list {
        position: absolute;
        width: 90%; /* Match input width */
        max-height: 200px;
        overflow-y: auto;
        background: white;
        border: 1px solid #d1d5db;
        border-top: none;
        border-radius: 0 0 12px 12px;
        box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        z-index: 100;
        left: 0;
        top: 100%;
    }

    .autocomplete-item {
        padding: 10px 15px;
        cursor: pointer;
        font-size: 0.95rem;
        color: #111827;
        font-family: monospace;
    }

    .autocomplete-item:hover {
        background: #e0e7ff;
        color: #4f46e5;
    }

    /* Wrap input and list in a parent for correct positioning */
    .input-and-suggestions {
        position: relative;
        width: 100%;
    }
    /* Ensure the input itself takes full width within its wrapper */
    .input-and-suggestions .form-control {
        width: 90% !important;
        border-radius: 12px;
        /* Redo border radius when list is visible */
    }

    /* --- NEW: LOADING MODAL STYLES --- */
    #loadingModal {
        display: none; /* Hidden by default */
        position: fixed;
        z-index: 5000; /* Very high z-index to block everything */
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.7); /* Blocking backdrop */
        backdrop-filter: blur(5px);
        justify-content: center;
        align-items: center;
    }
    .loading-content {
        text-align: center;
        color: white;
        padding: 30px;
        background: rgba(255, 255, 255, 0.1); 
        border-radius: 20px;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
    }
    .spinner {
        border: 6px solid #f3f3f3; 
        border-top: 6px solid #4f46e5; /* Primary Theme Color */
        border-radius: 50%;
        width: 60px;
        height: 60px;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-file-signature" style="color: #4f46e5;"></i> Issue Book</h2>
        <p>Checkout books to members or fulfill reservations</p>
    </div>
    
    <?php if ($message): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> <?php endif; ?>
    <?php if ($error): ?> <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> <?php endif; ?>

    <?php if ($step == 1): ?>
        <form method="POST" id="initForm" onsubmit="showLoadingModal()">
            <input type="hidden" name="step" value="2">
            <input type="hidden" name="mode" id="modeInput" value="<?php echo $mode; ?>">

            <div class="mode-toggle-container">
                <button type="button" class="mode-btn <?php echo $mode=='direct'?'active':''; ?>" onclick="setMode('direct')">
                    <i class="fas fa-barcode"></i> Direct Scan
                </button>
                <button type="button" class="mode-btn <?php echo $mode=='reservation'?'active':''; ?>" onclick="setMode('reservation')">
                    <i class="fas fa-ticket-alt"></i> From Reservation
                </button>
            </div>

            <div id="directSection" style="display: <?php echo $mode=='direct'?'block':'none'; ?>;">
                <div class="form-group">
                    <label>Book Copy UID (Barcode)</label>
                    <div class="input-and-suggestions">
                        <div class="input-wrapper">
                            <i class="fas fa-book"></i>
                            <input type="text" id="book_uid" name="book_uid" class="form-control" placeholder="Scan or type ID" autofocus autocomplete="off" oninput="fetchSuggestions('book', this)">
                            
                            <button type="button" class="btn-scan" onclick="startScanner('book_uid')" title="Scan Barcode/QR">
                                <i class="fas fa-qrcode"></i>
                            </button>
                        </div>
                        <div id="book_uid_suggestions" class="autocomplete-list" style="display: none;"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label>Member ID</label>
                    <div class="input-and-suggestions">
                        <div class="input-wrapper">
                            <i class="fas fa-user"></i>
                            <input type="text" id="member_uid" name="member_uid" class="form-control" placeholder="Enter Student/Staff ID" autocomplete="off" oninput="fetchSuggestions('member', this)">
                            
                            <button type="button" class="btn-scan" onclick="startScanner('member_uid')" title="Scan Member ID">
                                <i class="fas fa-qrcode"></i>
                            </button>
                        </div>
                        <div id="member_uid_suggestions" class="autocomplete-list" style="display: none;"></div>
                    </div>
                </div>
            </div>

            <div id="resSection" style="display: <?php echo $mode=='reservation'?'block':'none'; ?>;">
                <div class="form-group">
                    <label>Reservation ID</label>
                    <div class="input-and-suggestions">
                        <div class="input-wrapper">
                            <i class="fas fa-hashtag"></i>
                            <input type="text" id="reservation_uid" name="reservation_uid" class="form-control" placeholder="e.g. RES/INS/LIB/XXXXXX" autocomplete="off" oninput="fetchSuggestions('reservation', this)">
                            
                            <button type="button" class="btn-scan" onclick="startScanner('reservation_uid')" title="Scan Reservation ID">
                                <i class="fas fa-qrcode"></i>
                            </button>
                        </div>
                        <div id="reservation_uid_suggestions" class="autocomplete-list" style="display: none;"></div>
                    </div>
                </div>
                <div class="alert-warning">
                    <i class="fas fa-info-circle"></i> <span>Only **Accepted** reservations can be processed here.</span>
                </div>
            </div>

            <div style="margin-top: 25px;">
                <button type="submit" class="btn-main">Proceed to Verification <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>

    <?php elseif ($step == 3): ?>
        
        <?php if (isset($issue_data['reservation_id']) && $issue_data['reservation_id'] > 0): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> This issue will fulfill an active reservation.
            </div>
        <?php endif; ?>

        <div class="verify-grid">
            <div class="info-card">
                <h4><i class="fas fa-book"></i> Book Information</h4>
                <div class="info-row"><span class="info-label">Title:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['title']); ?></span></div>
                <div class="info-row"><span class="info-label">Author:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['author']); ?></span></div>
                
                <div class="info-row"><span class="info-label">Category:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['category']); ?></span></div>
                <div class="info-row"><span class="info-label">Edition:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['edition'] ?? '-'); ?></span></div>
                <div class="info-row"><span class="info-label">Publisher:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['publication'] ?? '-'); ?></span></div>
                <div class="info-row"><span class="info-label">ISBN:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['isbn'] ?? '-'); ?></span></div>

                <?php if ($issue_data['mode'] === 'direct'): ?>
                    <div style="margin-top: 15px; text-align: center;">
                        <span style="background: #e0e7ff; color: #3730a3; padding: 5px 10px; border-radius: 6px; font-family: monospace; font-weight: 700;">
                            <?php echo htmlspecialchars($issue_data['book_uid']); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="info-card">
                <h4><i class="fas fa-user"></i> Borrower Information</h4>
                <div class="info-row"><span class="info-label">Name:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['member_name']); ?></span></div>
                <div class="info-row"><span class="info-label">ID:</span> <span class="info-val"><?php echo htmlspecialchars($issue_data['member_uid']); ?></span></div>
                <div class="info-row"><span class="info-label">Current Loans:</span> <span class="info-val" style="color: #d97706;"><?php echo htmlspecialchars($issue_data['borrowed_count'] ?? 0); ?> Books</span></div>
                
                <?php if ($issue_data['mode'] === 'reservation'): ?>
                    <div style="margin-top: 15px; text-align: center;">
                        <span style="background: #d1fae5; color: #065f46; padding: 5px 10px; border-radius: 6px; font-size: 0.85rem; font-weight: 600;">
                            Res ID: <?php echo htmlspecialchars($issue_data['reservation_uid']); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="info-card" style="margin-bottom: 25px; text-align: center; padding: 15px;">
             <h4 style="margin: 0; color: #000;"><i class="fas fa-calendar-alt"></i> Due Date: <span style="color: #b91c1c;"><?php echo date('d M Y', strtotime('+' . get_setting($conn, 'max_borrow_days') . ' days')); ?></span></h4>
        </div>


        <form method="POST" id="finalForm">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="confirm_issue" value="1">
            <input type="hidden" name="mode" value="<?php echo $issue_data['mode']; ?>">
            <input type="hidden" name="member_id" value="<?php echo $issue_data['member_id']; ?>">
            <input type="hidden" name="book_id" value="<?php echo $issue_data['book_id']; ?>">
            <input type="hidden" name="reservation_id" value="<?php echo $issue_data['reservation_id']; ?>">

            <?php if ($issue_data['mode'] === 'direct'): ?>
                <input type="hidden" name="copy_id" value="<?php echo $issue_data['copy_id']; ?>">
                
            <?php elseif ($issue_data['mode'] === 'reservation'): ?>
                <div class="form-group">
                    <label>Select Copy to Issue</label>
                    <div class="input-wrapper">
                        <i class="fas fa-copy"></i>
                        <select name="copy_id" id="copySelect" class="form-control" required style="width: 100%; padding-left: 45px;">
                            <option value="">-- Select Available Copy --</option>
                            <?php foreach($issue_data['available_copies'] as $copy): ?>
                                <option value="<?php echo $copy['copy_id']; ?>" data-uid="<?php echo $copy['book_uid']; ?>">
                                    <?php echo htmlspecialchars($copy['book_uid']); ?> (Status: <?php echo htmlspecialchars($copy['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div style="display: flex; gap: 15px; margin-top: 30px;">
                <button type="button" onclick="window.location.href='issue_book.php'" class="btn-main btn-cancel">Cancel</button>
                <button type="button" onclick="showConfirmModal()" class="btn-main btn-confirm">Confirm Issue</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<div id="confirmModal" class="modal">
    <div class="modal-content">
        <i class="fas fa-check-circle" style="font-size: 4rem; color: #059669; margin-bottom: 20px; display: block;"></i>
        <h3 style="color: #000; margin: 0 0 10px 0;">Confirm Issue?</h3>
        <p style="color: #374151;">You are about to issue copy:</p>
        
        <div style="background: #f8fafc; padding: 10px; border-radius: 10px; border: 1px dashed #d1d5db; margin: 15px 0;">
            <h2 id="modalCopyID" style="margin: 0; color: #4f46e5; font-family: monospace; font-weight: 700;"></h2>
        </div>
        <p style="color: #b91c1c; font-weight: 600;">Due Date: <?php echo date('d M Y', strtotime('+' . get_setting($conn, 'max_borrow_days') . ' days')); ?></p>

        <div class="modal-actions">
            <button onclick="submitFinal()" class="modal-btn">Yes, Issue Book</button>
            <button onclick="closeModal()" class="modal-btn">Cancel</button>
        </div>
    </div>
</div>

<div id="scannerModal">
    <div id="scanner-container">
        <h3 style="text-align: center; margin-top: 0;">Scan Code</h3>
        <div id="reader"></div>
        <button onclick="stopScanner()" class="btn-main" style="background: #ef4444; margin-top: 15px; width: auto; display: inline-block; padding: 10px 20px;">Close Scanner</button>
    </div>
</div>

<div id="loadingModal">
    <div class="loading-content">
        <div class="spinner"></div>
        <h3>Processing...</h3>
        <p>Please wait while we complete the transaction.</p>
    </div>
</div>

<script>
function setMode(m) {
    document.getElementById('modeInput').value = m;
    document.getElementById('directSection').style.display = m==='direct' ? 'block' : 'none';
    document.getElementById('resSection').style.display = m==='reservation' ? 'block' : 'none';
    
    // Update active button state
    document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
    document.querySelector('.mode-btn[onclick="setMode(\'' + m + '\')"]').classList.add('active');
}

function showConfirmModal() {
    let copyUid = '';
    let copyId = '';
    
    <?php if(isset($issue_data) && $issue_data['mode'] === 'direct'): ?>
        copyUid = '<?php echo $issue_data['book_uid']; ?>';
        copyId = '<?php echo $issue_data['copy_id']; ?>';
    <?php else: ?>
        const sel = document.getElementById('copySelect');
        if(!sel || !sel.value) { alert("Please select a book copy first."); return; }
        // For reservation mode, we need the actual selected copy UID for the modal display
        copyUid = sel.options[sel.selectedIndex].text.split(' (Status:')[0].trim();
        copyId = sel.value;
    <?php endif; ?>
    
    // Ensure copy_id is set in the hidden input for reservation mode final submission
    const finalForm = document.getElementById('finalForm');
    let copyIdInput = finalForm.querySelector('input[name="copy_id"]');
    if (!copyIdInput) {
        copyIdInput = document.createElement('input');
        copyIdInput.type = 'hidden';
        copyIdInput.name = 'copy_id';
        finalForm.appendChild(copyIdInput);
    }
    copyIdInput.value = copyId;
    
    document.getElementById('modalCopyID').innerText = copyUid;
    document.getElementById('confirmModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('confirmModal').style.display = 'none';
}

function showLoadingModal() {
    document.getElementById('loadingModal').style.display = 'flex';
}

function submitFinal() {
    // Show loading modal before submitting the final form
    showLoadingModal();
    document.getElementById('finalForm').submit();
}

// --- NEW AUTO-SUGGESTION JAVASCRIPT ---
function fetchSuggestions(uidType, inputElement) {
    const query = inputElement.value;
    const suggestionBox = document.getElementById(uidType + '_uid_suggestions');
    
    if (query.length < 3) {
        suggestionBox.style.display = 'none';
        inputElement.style.borderRadius = '12px';
        return;
    }

    // Debouncing
    if (inputElement.timeout) clearTimeout(inputElement.timeout);

    inputElement.timeout = setTimeout(() => {
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
                            inputElement.style.borderRadius = '12px';
                        };
                        suggestionBox.appendChild(itemElement);
                    });
                    suggestionBox.style.display = 'block';
                    inputElement.style.borderRadius = '12px 12px 0 0';
                } else {
                    suggestionBox.style.display = 'none';
                    inputElement.style.borderRadius = '12px';
                }
            })
            .catch(error => {
                console.error('Error fetching suggestions:', error);
                suggestionBox.style.display = 'none';
                inputElement.style.borderRadius = '12px';
            });
    }, 300);
}

// Hide suggestions when clicking outside
document.addEventListener('click', function(e) {
    // A function to reset the input border radius and hide the suggestion box
    function hideAndReset(inputId, suggestionBoxId) {
        const input = document.getElementById(inputId);
        const box = document.getElementById(suggestionBoxId);
        if (box && box.style.display !== 'none') {
            box.style.display = 'none';
            input.style.borderRadius = '12px';
        }
    }
    
    // Check if the click was outside any of the suggestion elements
    if (!e.target.closest('.input-and-suggestions')) {
        hideAndReset('book_uid', 'book_uid_suggestions');
        hideAndReset('member_uid', 'member_uid_suggestions');
        hideAndReset('reservation_uid', 'reservation_uid_suggestions');
    }
});

// Ensure initial state is correctly set on load
document.addEventListener('DOMContentLoaded', function() {
    const initialMode = document.getElementById('modeInput').value;
    document.querySelector('.mode-btn[onclick="setMode(\'' + initialMode + '\')"]').classList.add('active');
});

// --- NEW: SCANNER LOGIC ---
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
}

function stopScanner() {
    document.getElementById('scannerModal').style.display = 'none';
    // We don't clear the scanner instance so it resumes faster next time,
    // but we hide the modal. To fully stop camera stream:
    if(html5QrcodeScanner) {
        html5QrcodeScanner.clear().then(() => {
            html5QrcodeScanner = null; // Reset to allow re-initialization
        }).catch(error => {
            console.error("Failed to clear scanner", error);
        });
    }
}
</script>

<?php admin_footer(); close_db_connection($conn); ?>