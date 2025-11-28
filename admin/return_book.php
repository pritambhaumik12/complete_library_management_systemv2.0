<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';

// Check for flash message from redirect
if (isset($_SESSION['flash_success'])) {
    $message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$step = $_POST['step'] ?? 1;
$book_uid = $_POST['book_uid'] ?? '';
$admin_id = $_SESSION['admin_id'];
$return_date = date('Y-m-d');
$currency = get_setting($conn, 'currency_symbol');

// --- 0. GET ADMIN LIBRARY CONTEXT ---
$is_super = is_super_admin($conn);
$stmt_admin = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
$stmt_admin->bind_param("i", $admin_id);
$stmt_admin->execute();
$my_lib_id = $stmt_admin->get_result()->fetch_assoc()['library_id'] ?? 0;

// --- Helper function to fetch circulation record details ---
function fetch_circulation_details($conn, $book_uid) {
    $stmt = $conn->prepare("
        SELECT 
            tc.circulation_id, tc.member_id, tc.issue_date, tc.due_date, tbc.copy_id, tbc.book_id, tb.title, tb.author, tb.price, tb.library_id, l.library_name,
            tm.full_name, tm.member_uid
        FROM 
            tbl_circulation tc
        JOIN 
            tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
        JOIN
            tbl_books tb ON tbc.book_id = tb.book_id
        LEFT JOIN 
            tbl_libraries l ON tb.library_id = l.library_id
        JOIN
            tbl_members tm ON tc.member_id = tm.member_id
        WHERE 
            tbc.book_uid = ? AND tc.status = 'Issued'
    ");
    $stmt->bind_param("s", $book_uid);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// --- Step 2: Validate Input and Show Details ---
if ($step == 2) {
    $book_uid = trim($_POST['book_uid'] ?? '');
    if (empty($book_uid)) {
        $error = "Please enter the Book ID (Book UID).";
        $step = 1;
    } else {
        $record = fetch_circulation_details($conn, $book_uid);

        if (!$record) {
            $error = "Book ID <strong>{$book_uid}</strong> is not currently issued.";
            $step = 1;
        } else {
            if (!$is_super && $my_lib_id != $record['library_id']) {
                $error = "Access Denied: This book belongs to <strong>" . htmlspecialchars($record['library_name'] ?? 'Unassigned') . "</strong>. You cannot process returns for other libraries.";
                $step = 1;
            } else {
                $due_date = $record['due_date'];
                $fine_amount = calculate_fine($conn, $due_date, $return_date);
                $overdue_days = max(0, floor((strtotime($return_date) - strtotime($due_date)) / (60 * 60 * 24)));
                $fine_per_day = get_setting($conn, 'fine_per_day');

                $return_details = [
                    'circulation_id' => $record['circulation_id'],
                    'member_id' => $record['member_id'],
                    'copy_id' => $record['copy_id'],
                    'book_id' => $record['book_id'],
                    'book_uid' => $book_uid,
                    'title' => $record['title'],
                    'author' => $record['author'],
                    'price' => $record['price'],
                    'member_name' => $record['full_name'],
                    'member_uid' => $record['member_uid'],
                    'issue_date' => $record['issue_date'],
                    'due_date' => $due_date,
                    'fine_amount' => $fine_amount,
                    'overdue_days' => $overdue_days,
                    'fine_per_day' => $fine_per_day,
                ];
                $step = 3;
            }
        }
    }
}
// --- Step 3: Process Fine or Final Return ---
elseif ($step == 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $circulation_id = (int)$_POST['circulation_id'];
    $member_id = (int)$_POST['member_id'];
    $copy_id = (int)$_POST['copy_id'];
    $book_id = (int)$_POST['book_id'];
    $fine_amount = (float)$_POST['fine_amount'];
    $book_uid = $_POST['book_uid'];
    $return_type = $_POST['return_type'] ?? 'Return'; 
    $lost_fine_amount = (float)($_POST['lost_fine_amount'] ?? 0);

    // Re-fetch for display if error
    $record = fetch_circulation_details($conn, $book_uid);
    if ($record) {
        $return_details = [
            'circulation_id' => $record['circulation_id'],
            'member_id' => $record['member_id'],
            'copy_id' => $record['copy_id'],
            'book_id' => $record['book_id'],
            'book_uid' => $book_uid,
            'title' => $record['title'],
            'author' => $record['author'],
            'price' => $record['price'],
            'member_name' => $record['full_name'],
            'member_uid' => $record['member_uid'],
            'issue_date' => $record['issue_date'],
            'due_date' => $record['due_date'],
            'fine_amount' => $fine_amount,
            'overdue_days' => max(0, floor((strtotime($return_date) - strtotime($record['due_date'])) / (60 * 60 * 24))),
            'fine_per_day' => get_setting($conn, 'fine_per_day'),
        ];
    }

    if ($action === 'save_lost_fine_outstanding') {
        if ($lost_fine_amount <= 0) {
            $error = "Lost fine amount is mandatory and must be greater than zero.";
            $step = 3; 
        } else {
            $conn->begin_transaction();
            try {
                // 1. Insert Lost Fine
                $fine_type = 'Lost Book';
                $is_outstanding = 1;
                $stmt = $conn->prepare("INSERT INTO tbl_fines (circulation_id, member_id, fine_type, fine_amount, is_outstanding, fine_date) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iisdis", $circulation_id, $member_id, $fine_type, $lost_fine_amount, $is_outstanding, $return_date);
                if (!$stmt->execute()) throw new Exception($conn->error);
                
                // 2. Update Circ to Lost
                $stmt = $conn->prepare("UPDATE tbl_circulation SET status = 'Lost', return_date = ?, returned_by_admin_id = ? WHERE circulation_id = ?");
                $stmt->bind_param("sii", $return_date, $admin_id, $circulation_id);
                if (!$stmt->execute()) throw new Exception($conn->error);

                // 3. Update Copy to Lost
                $stmt = $conn->prepare("UPDATE tbl_book_copies SET status = 'Lost' WHERE copy_id = ?");
                $stmt->bind_param("i", $copy_id);
                if (!$stmt->execute()) throw new Exception($conn->error);

                $conn->commit();
                // --- START EMAIL LOGIC ---
                $stmt_mail = $conn->prepare("SELECT email, full_name FROM tbl_members WHERE member_id = ?");
                $stmt_mail->bind_param("i", $member_id);
                $stmt_mail->execute();
                $mail_data = $stmt_mail->get_result()->fetch_assoc();

                if ($mail_data && !empty($mail_data['email'])) {
                    $subject = "Book Returned: " . $book_uid;
                    $message = "
                        <h3>Book Returned Successfully</h3>
                        <p>Dear {$mail_data['full_name']},</p>
                        <p>You have successfully returned the following book:</p>
                        <ul>
                            <li><strong>Book UID:</strong> {$book_uid}</li>
                            <li><strong>Return Date:</strong> {$return_date}</li>
                        </ul>
                        <p>Thank you for using the library.</p>
                    ";
                    send_system_email($mail_data['email'], $mail_data['full_name'], $subject, $message);
                }
                $_SESSION['flash_success'] = "Book marked as **LOST**. Fine recorded.";
                redirect('return_book.php'); 

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Transaction failed: " . $e->getMessage();
                $step = 3; 
            }
        }
    }
    
    elseif ($action === 'process_return_and_fine' && $fine_amount > 0) {
        $payment_method = trim($_POST['payment_method'] ?? '');
        $transaction_id = trim($_POST['transaction_id'] ?? NULL);
        
        if (empty($payment_method)) {
            $error = "Payment method is required.";
            $step = 3; 
        } elseif ($payment_method !== 'Cash' && empty($transaction_id)) {
            $error = "Transaction ID is required for non-cash payments.";
            $step = 3; 
        } else {
            $conn->begin_transaction();
            try {
                // 1. Insert Fine
                $fine_type = 'Late Return';
                $stmt = $conn->prepare("INSERT INTO tbl_fines (circulation_id, member_id, fine_type, fine_amount, fine_date) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iisds", $circulation_id, $member_id, $fine_type, $fine_amount, $return_date);
                if (!$stmt->execute()) throw new Exception($conn->error);
                $fine_id = $conn->insert_id;

                // 2. Mark Paid
                $paid_on = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE tbl_fines SET payment_status = 'Paid', payment_method = ?, transaction_id = ?, paid_on = ?, collected_by_admin_id = ? WHERE fine_id = ?");
                $stmt->bind_param("sssii", $payment_method, $transaction_id, $paid_on, $admin_id, $fine_id);
                if (!$stmt->execute()) throw new Exception($conn->error);

                // 3. Return Book (Circulation)
                $stmt = $conn->prepare("UPDATE tbl_circulation SET status = 'Returned', return_date = ?, returned_by_admin_id = ? WHERE circulation_id = ?");
                $stmt->bind_param("sii", $return_date, $admin_id, $circulation_id);
                if (!$stmt->execute()) throw new Exception($conn->error);

                // 4. Update Copy to Available
                $stmt = $conn->prepare("UPDATE tbl_book_copies SET status = 'Available' WHERE copy_id = ?");
                $stmt->bind_param("i", $copy_id);
                if (!$stmt->execute()) throw new Exception($conn->error);

                // 5. Update Book Quantity
                $stmt = $conn->prepare("UPDATE tbl_books SET available_quantity = available_quantity + 1 WHERE book_id = ?");
                $stmt->bind_param("i", $book_id);
                if (!$stmt->execute()) throw new Exception($conn->error);

                $conn->commit();
                // --- START EMAIL LOGIC ---
                $stmt_mail = $conn->prepare("SELECT email, full_name FROM tbl_members WHERE member_id = ?");
                $stmt_mail->bind_param("i", $member_id);
                $stmt_mail->execute();
                $mail_data = $stmt_mail->get_result()->fetch_assoc();

                if ($mail_data && !empty($mail_data['email'])) {
                    $formatted_amount = number_format($fine_amount, 2);
                    $subject = "Book Returned with Fine Payment";
                    $message = "
                        <h3>Book Returned & Fine Paid</h3>
                        <p>Dear {$mail_data['full_name']},</p>
                        <p>You have returned the book <strong>{$book_uid}</strong>.</p>
                        <p>A fine of <strong>{$currency}{$formatted_amount}</strong> was generated and has been marked as <strong>PAID</strong>.</p>
                        <p>Payment Method: {$payment_method}</p>
                    ";
                    send_system_email($mail_data['email'], $mail_data['full_name'], $subject, $message);
                }
                $_SESSION['flash_success'] = "Book returned and fine paid successfully.";
                redirect('return_book.php'); 

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Transaction failed: " . $e->getMessage();
                $step = 3; 
            }
        }

    } elseif ($action === 'confirm_return' && $fine_amount == 0) {
        $conn->begin_transaction();
        try {
            // 1. Update Circ
            $stmt = $conn->prepare("UPDATE tbl_circulation SET status = 'Returned', return_date = ?, returned_by_admin_id = ? WHERE circulation_id = ?");
            $stmt->bind_param("sii", $return_date, $admin_id, $circulation_id);
            if (!$stmt->execute()) throw new Exception($conn->error);

            // 2. Update Copy
            $stmt = $conn->prepare("UPDATE tbl_book_copies SET status = 'Available' WHERE copy_id = ?");
            $stmt->bind_param("i", $copy_id);
            if (!$stmt->execute()) throw new Exception($conn->error);

            // 3. Update Quantity
            $stmt = $conn->prepare("UPDATE tbl_books SET available_quantity = available_quantity + 1 WHERE book_id = ?");
            $stmt->bind_param("i", $book_id);
            if (!$stmt->execute()) throw new Exception($conn->error);

            $conn->commit();
            // --- START EMAIL LOGIC ---
            $stmt_mail = $conn->prepare("SELECT email, full_name FROM tbl_members WHERE member_id = ?");
            $stmt_mail->bind_param("i", $member_id);
            $stmt_mail->execute();
            $mail_data = $stmt_mail->get_result()->fetch_assoc();

            if ($mail_data && !empty($mail_data['email'])) {
                $subject = "Book Returned: " . $book_uid;
                $message = "
                    <h3>Book Returned Successfully</h3>
                    <p>Dear {$mail_data['full_name']},</p>
                    <p>You have successfully returned the following book:</p>
                    <ul>
                        <li><strong>Book UID:</strong> {$book_uid}</li>
                        <li><strong>Return Date:</strong> {$return_date}</li>
                    </ul>
                    <p>Thank you for using the library.</p>
                ";
                send_system_email($mail_data['email'], $mail_data['full_name'], $subject, $message);
            }
            $_SESSION['flash_success'] = "Book **{$book_uid}** returned successfully.";
            redirect('return_book.php'); 

        } catch (Exception $e) {
            $conn->rollback();
            $error = "Book return failed: " . $e->getMessage();
            $step = 3; 
        }
    }
}

admin_header('Return Book');
?>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<style>
    /* Glass Components */
    .glass-card {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.7);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.1);
        border-radius: 20px;
        padding: 30px;
        margin: 0 auto;
        max-width: 800px;
        animation: fadeIn 0.5s ease-out;
        color: #111827;
    }

    .card-header { text-align: center; margin-bottom: 30px; }
    .card-header h2 { margin: 0; color: #000; font-size: 1.8rem; display: flex; justify-content: center; align-items: center; gap: 10px; }
    .card-header p { color: #374151; margin-top: 5px; }

    /* Input Styles */
    .form-group { margin-bottom: 20px; text-align: left; }
    .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: #111827; margin-bottom: 8px; }
    
    .input-wrapper { position: relative; }
    .input-wrapper i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #6b7280; }
    
    .form-control {
        width: 90%; padding: 14px 14px 14px 45px;
        border: 1px solid #d1d5db; 
        background: rgba(255, 255, 255, 0.9); 
        border-radius: 12px;
        font-size: 1rem; color: #111827; transition: all 0.3s;
    }
    .form-control:focus { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); outline: none; }

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

    /* Buttons */
    .btn-main {
        background: linear-gradient(135deg, #4f46e5, #3730a3); color: white; border: none;
        padding: 15px 30px; border-radius: 12px; font-weight: 700; font-size: 1rem;
        cursor: pointer; width: 100%; transition: transform 0.2s;
        display: flex; align-items: center; justify-content: center; gap: 10px;
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
    }
    .btn-main:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3); }

    /* Detail Cards (Step 3) */
    .verify-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; text-align: left; }
    .info-card { 
        background: rgba(255, 255, 255, 0.8); 
        border: 1px solid #e5e7eb; 
        border-radius: 16px; 
        padding: 20px; 
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .info-card h4 { margin: 0 0 10px 0; color: #4f46e5; font-size: 0.9rem; text-transform: uppercase; }
    .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.95rem; }
    .info-label { color: #4b5563; }
    .info-val { font-weight: 700; color: #111827; }

    /* Fine Status Box */
    .status-box { padding: 20px; border-radius: 12px; text-align: center; margin-bottom: 20px; border: 1px solid transparent; }
    .status-ok { background: #d1fae5; border-color: #a7f3d0; color: #065f46; } 
    .status-fine { background: #fee2e2; border-color: #fecaca; color: #991b1b; } 
    .status-box h3 { margin: 0 0 5px 0; font-size: 1.2rem; font-weight: 700; }
    .status-fine strong { color: #991b1b; }

    /* Action Buttons Group */
    .action-group { display: flex; gap: 15px; margin-top: 20px; }
    .btn-action { flex: 1; padding: 12px; border-radius: 10px; border: none; font-weight: 600; cursor: pointer; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; transition: background 0.2s; }
    .btn-success { background: #059669; } 
    .btn-danger { background: #b91c1c; } 
    .btn-secondary { background: #4b5563; }

    .btn-success:hover { background: #047857; }
    .btn-danger:hover { background: #991b1b; }
    .btn-secondary:hover { background: #374151; }

    /* Modal */
    .modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(17, 24, 39, 0.7); backdrop-filter: blur(5px); align-items: center; justify-content: center; }
    .modal-content { 
        background: #fff; padding: 30px; border-radius: 20px; width: 90%; max-width: 450px; position: relative; 
        animation: popUp 0.3s ease; 
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    .modal-content h3 { color: #000; }
    .modal-content p strong { color: #b91c1c !important; }
    
    .close-modal { position: absolute; right: 20px; top: 20px; font-size: 1.5rem; cursor: pointer; color: #6b7280; }

    /* LOADING OVERLAY STYLES */
    #loadingOverlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9); /* Opaque white */
        backdrop-filter: blur(5px);
        z-index: 99999; /* Very high z-index to block everything */
        display: none; /* Hidden by default */
        justify-content: center;
        align-items: center;
        flex-direction: column;
        pointer-events: all; /* Blocks clicks */
    }

    .loading-content {
        background: white;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        text-align: center;
        border: 1px solid #e5e7eb;
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #e0e7ff;
        border-top: 5px solid #4f46e5;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px auto;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 1.2rem;
        font-weight: 700;
        color: #111827;
        margin: 0;
    }

    .loading-subtext {
        color: #6b7280;
        font-size: 0.9rem;
        margin-top: 5px;
    }

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

    /* Alerts */
    .alert { padding: 15px; border-radius: 12px; margin-bottom: 25px; text-align: center; font-weight: 600; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @media (max-width: 768px) { .verify-grid { grid-template-columns: 1fr; } .action-group { flex-direction: column; } }

    /* Auto Suggestion */
    .autocomplete-list {
        position: absolute; width: 90%; max-height: 200px; overflow-y: auto; background: white;
        border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 12px 12px;
        box-shadow: 0 8px 15px rgba(0,0,0,0.1); z-index: 100; left: 0; top: 100%;
    }
    .autocomplete-item { padding: 10px 15px; cursor: pointer; font-size: 0.95rem; color: #111827; font-family: monospace; }
    .autocomplete-item:hover { background: #e0e7ff; color: #4f46e5; }
    .input-and-suggestions { position: relative; width: 90%; }
    .input-and-suggestions .form-control { width: 100% !important; border-radius: 12px; }
</style>

<div id="loadingOverlay">
    <div class="loading-content">
        <div class="spinner"></div>
        <h3 class="loading-text">Processing Return...</h3>
        <p class="loading-subtext">Please wait while we update the records.</p>
    </div>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-undo-alt" style="color: #4f46e5;"></i> Return Book</h2>
        <p>Scan or enter **Book UID** to process return</p>
    </div>
    
    <?php if ($message): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> <?php endif; ?>
    <?php if ($error): ?> <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error; ?></div> <?php endif; ?>

    <?php if ($step == 1): ?>
        <form method="POST">
            
            <input type="hidden" name="step" value="2">
            <div class="form-group">
                <label>Book Copy UID (Barcode)</label>
                <div class="input-and-suggestions">
                    <div class="input-wrapper">
                        <i class="fas fa-barcode"></i>
                        <input type="text" id="book_uid" name="book_uid" class="form-control" placeholder="e.g., LMS/BOOK/001-1" autofocus required autocomplete="off" oninput="fetchSuggestions('issued_book', this)">
                        <button type="button" class="btn-scan" onclick="startScanner('book_uid')" title="Scan Barcode/QR">
                            <i class="fas fa-qrcode"></i>
                        </button>
                    </div>
                    <div id="book_uid_suggestions" class="autocomplete-list" style="display: none;"></div>
                </div>
            </div>
            <button type="submit" class="btn-main">Check Book Details <i class="fas fa-arrow-right"></i></button>
        </form>

    <?php elseif ($step == 3): ?>
        <?php
            if (!isset($return_details)) { echo "<script>window.location.href='return_book.php';</script>"; exit; }
            $fine_amount = $return_details['fine_amount'];
            $overdue_days = $return_details['overdue_days'];
        ?>
        
        <div class="verify-grid">
            <div class="info-card">
                <h4><i class="fas fa-book"></i> Book Info</h4>
                <div class="info-row"><span class="info-label">Title:</span> <span class="info-val"><?php echo htmlspecialchars($return_details['title']); ?></span></div>
                <div class="info-row"><span class="info-label">Author:</span> <span class="info-val"><?php echo htmlspecialchars($return_details['author']); ?></span></div>
                <div class="info-row"><span class="info-label">Price:</span> <span class="info-val"><?php echo $currency . number_format($return_details['price'], 2); ?></span></div>
                <div class="info-row"><span class="info-label">UID:</span> <span class="info-val"><?php echo htmlspecialchars($return_details['book_uid']); ?></span></div>
            </div>
            <div class="info-card">
                <h4><i class="fas fa-user"></i> Borrower Info</h4>
                <div class="info-row"><span class="info-label">Name:</span> <span class="info-val"><?php echo htmlspecialchars($return_details['member_name']); ?></span></div>
                <div class="info-row"><span class="info-label">Member UID:</span> <span class="info-val"><?php echo htmlspecialchars($return_details['member_uid']); ?></span></div>
                <div class="info-row"><span class="info-label">Issue Date:</span> <span class="info-val"><?php echo date('d M Y', strtotime($return_details['issue_date'])); ?></span></div>
                <div class="info-row"><span class="info-label">Due Date:</span> <span class="info-val"><?php echo date('d M Y', strtotime($return_details['due_date'])); ?></span></div>
            </div>
        </div>

        <?php if ($fine_amount > 0): ?>
            <div class="status-box status-fine">
                <h3><i class="fas fa-exclamation-triangle"></i> Overdue by <?php echo $overdue_days; ?> Days</h3>
                <p>Total Fine Due: <strong style="color: inherit;"><?php echo $currency . number_format($fine_amount, 2); ?></strong></p>
            </div>
        <?php else: ?>
            <div class="status-box status-ok">
                <h3><i class="fas fa-check-circle"></i> Returned On Time</h3>
                <p>No fines applicable.</p>
            </div>
        <?php endif; ?>

        <form method="POST" id="returnForm">
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="circulation_id" value="<?php echo $return_details['circulation_id']; ?>">
            <input type="hidden" name="member_id" value="<?php echo $return_details['member_id']; ?>">
            <input type="hidden" name="copy_id" value="<?php echo $return_details['copy_id']; ?>">
            <input type="hidden" name="book_id" value="<?php echo $return_details['book_id']; ?>">
            <input type="hidden" name="book_uid" value="<?php echo $return_details['book_uid']; ?>">
            <input type="hidden" name="fine_amount" value="<?php echo $fine_amount; ?>">

            <div class="form-group">
                <label>Return Condition</label>
                <div class="input-wrapper">
                    <i class="fas fa-clipboard-check"></i>
                    <select id="return_type" name="return_type" class="form-control" style="width: 100%; padding-left: 45px;">
                        <option value="Return">Normal Return (Book Present)</option>
                        <option value="Lost">Lost Book (Charge Fine)</option>
                    </select>
                </div>
            </div>

            <div id="lostFineGroup" style="display: none; margin-bottom: 20px;">
                <div class="form-group">
                    <label>Lost Book Fee</label>
                    <div class="input-wrapper">
                        <i class="fas fa-coins"></i>
                        <input type="number" step="0.01" id="lost_fine_amount" name="lost_fine_amount" class="form-control" placeholder="Enter replacement cost" style="width: 90%;">
                    </div>
                </div>
            </div>

            <div class="action-group">
                <button type="submit" id="btnConfirm" name="action" value="confirm_return" class="btn-action btn-success">
                    <i class="fas fa-check"></i> Confirm Return
                </button>
                
                <?php if ($fine_amount > 0): ?>
                    <button type="button" id="btnPayFine" class="btn-action btn-danger" onclick="document.getElementById('paymentModal').style.display='flex';">
                        <i class="fas fa-money-bill"></i> Pay Fine & Return
                    </button>
                <?php endif; ?>

                <button type="submit" id="btnLost" name="action" value="save_lost_fine_outstanding" class="btn-action btn-secondary" style="display: none;">
                    <i class="fas fa-file-invoice"></i> Mark Lost & Save Fine
                </button>
            </div>
            
            <div style="margin-top: 15px; text-align: center;">
                <a href="return_book.php" style="color: #4b5563; text-decoration: none; font-size: 0.9rem;">Cancel Process</a>
            </div>
        </form>
    <?php endif; ?>
</div>

<div id="paymentModal" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="document.getElementById('paymentModal').style.display='none';">&times;</span>
        <h3 style="color: #000; margin-top: 0;">Process Payment</h3>
        
        <div style="background: #f3f4f6; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px dashed #d1d5db;">
            <p style="margin: 5px 0; color: #4b5563;">Total Fine: <strong style="color: #b91c1c; font-size: 1.2rem;"><?php echo $currency . number_format($fine_amount, 2); ?></strong></p>
        </div>

        <form method="POST">
            
            <input type="hidden" name="step" value="3">
            <input type="hidden" name="action" value="process_return_and_fine">
            <input type="hidden" name="circulation_id" value="<?php echo $return_details['circulation_id']; ?>">
            <input type="hidden" name="member_id" value="<?php echo $return_details['member_id']; ?>">
            <input type="hidden" name="copy_id" value="<?php echo $return_details['copy_id']; ?>">
            <input type="hidden" name="book_id" value="<?php echo $return_details['book_id']; ?>">
            <input type="hidden" name="book_uid" value="<?php echo $return_details['book_uid']; ?>">
            <input type="hidden" name="fine_amount" value="<?php echo $fine_amount; ?>">
            <input type="hidden" name="return_type" value="Return">

            <div class="form-group">
                <label>Payment Method</label>
                <div class="input-wrapper">
                    <i class="fas fa-wallet"></i>
                    <select name="payment_method" class="form-control" required style="width: 100%; padding-left: 45px;">
                        <option value="Cash">Cash</option>
                        <option value="Card">Card</option>
                        <option value="Online Transfer">Online Transfer</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Transaction ID (Optional)</label>
                <div class="input-wrapper">
                    <i class="fas fa-receipt"></i>
                    <input type="text" name="transaction_id" class="form-control" placeholder="e.g. TXN-12345">
                </div>
            </div>
            
            <button type="submit" class="btn-main" style="background: #059669;">Complete Payment & Return</button>
        </form>
    </div>
</div>

<div id="scannerModal">
    <div id="scanner-container">
        <h3 style="text-align: center; margin-top: 0;">Scan Code</h3>
        <div id="reader"></div>
        <button onclick="stopScanner()" class="btn-main" style="background: #ef4444; margin-top: 15px; width: auto; display: inline-block; padding: 10px 20px;">Close Scanner</button>
    </div>
</div>

<script>
    const returnTypeSelect = document.getElementById('return_type');
    const lostFineGroup = document.getElementById('lostFineGroup');
    const lostFineInput = document.getElementById('lost_fine_amount');
    const loadingOverlay = document.getElementById('loadingOverlay');

    // Buttons
    const btnConfirm = document.getElementById('btnConfirm');
    const btnPayFine = document.getElementById('btnPayFine');
    const btnLost = document.getElementById('btnLost');
    
    // Check if the variables are defined in the PHP block before using them
    const hasFine = <?php echo (isset($fine_amount) && $fine_amount > 0) ? 'true' : 'false'; ?>;

    if(returnTypeSelect) {
        returnTypeSelect.addEventListener('change', function() {
            if (this.value === 'Lost') {
                // Show Lost Options
                lostFineGroup.style.display = 'block';
                lostFineInput.required = true;
                
                btnLost.style.display = 'flex';
                if(btnConfirm) btnConfirm.style.display = 'none';
                if(btnPayFine) btnPayFine.style.display = 'none';
            } else {
                // Show Normal Options
                lostFineGroup.style.display = 'none';
                lostFineInput.required = false;
                
                btnLost.style.display = 'none';
                if(hasFine) {
                    // If fine exists, show Pay Fine button, hide simple confirm
                    if(btnConfirm) btnConfirm.style.display = 'none'; 
                    if(btnPayFine) btnPayFine.style.display = 'flex';
                } else {
                    // No fine, show simple confirm
                    if(btnConfirm) btnConfirm.style.display = 'flex';
                    if(btnPayFine) btnPayFine.style.display = 'none';
                }
            }
        });
        
        // Initial Run
        returnTypeSelect.dispatchEvent(new Event('change'));
    }
    
    // Close modal on outside click
    window.onclick = function(e) {
        if(e.target.id === 'paymentModal') e.target.style.display = 'none';
    }

    // --- AUTO-SUGGESTION JAVASCRIPT ---
    let debounceTimer;

    function fetchSuggestions(uidType, inputElement) {
        const query = inputElement.value;
        const suggestionBox = document.getElementById(inputElement.id + '_suggestions');
        
        if (query.length < 3) {
            suggestionBox.style.display = 'none';
            inputElement.style.borderRadius = '12px';
            return;
        }

        if (debounceTimer) clearTimeout(debounceTimer);

        debounceTimer = setTimeout(() => {
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

    // Expose fetchSuggestions globally
    window.fetchSuggestions = fetchSuggestions;

    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.input-and-suggestions')) {
            const input = document.getElementById('book_uid');
            const box = document.getElementById('book_uid_suggestions');
            if (box && box.style.display !== 'none') {
                box.style.display = 'none';
                input.style.borderRadius = '12px';
            }
        }
    });

    // --- SCANNER LOGIC ---
    let html5QrcodeScanner = null;
    let currentInputId = null;

    function startScanner(inputId) {
        currentInputId = inputId;
        document.getElementById('scannerModal').style.display = 'flex';

        if (html5QrcodeScanner === null) {
            html5QrcodeScanner = new Html5QrcodeScanner(
                "reader", 
                { fps: 10, qrbox: {width: 250, height: 250} },
                false
            );
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }
    }

    function onScanSuccess(decodedText, decodedResult) {
        if (currentInputId) {
            const inputField = document.getElementById(currentInputId);
            inputField.value = decodedText;
            stopScanner();
        }
    }

    function onScanFailure(error) {
        // handle scan failure, usually better to ignore
    }

    function stopScanner() {
        document.getElementById('scannerModal').style.display = 'none';
        if(html5QrcodeScanner) {
            html5QrcodeScanner.clear().then(() => {
                html5QrcodeScanner = null; 
            }).catch(error => {
                console.error("Failed to clear scanner", error);
            });
        }
    }

    // --- LOADING SPINNER TRIGGER ---
    document.addEventListener('DOMContentLoaded', function() {
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            form.addEventListener('submit', function() {
                // Show loader only if form is valid
                if (form.checkValidity()) {
                    loadingOverlay.style.display = 'flex';
                }
            });
        });
    });
</script>

<?php admin_footer(); close_db_connection($conn); ?>