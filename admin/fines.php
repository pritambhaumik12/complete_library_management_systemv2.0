<?php
/**
 * Fine Management Page (fines.php)
 * Displays outstanding and paid fines, and handles the collection of outstanding fines.
 * Features: Glassmorphism design, search, pagination for history, and modal for payment.
 */
require_once '../includes/functions.php';
require_admin_login();
global $conn;

// --- Setup and Initial Variables ---
$message = '';
$error = '';
// Assuming get_setting() exists in functions.php and returns system configuration values
$currency = get_setting($conn, 'currency_symbol');
$fine_per_day = (float)get_setting($conn, 'fine_per_day');
$inst_init = get_setting($conn, 'institution_initials') ?: 'INS'; // Fetch Institution Initials
$search_query = trim($_GET['search'] ?? ''); 

// --- 0. DETERMINE ADMIN SCOPE ---
$is_super = is_super_admin($conn);
$admin_id = $_SESSION['admin_id'];
$library_filter_clause = "";
$lib_params = [];
$lib_types = "";
$my_lib_id = 0; // Initialize

if (!$is_super) {
    // Fetch Admin's Assigned Library
    $stmt_lib = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
    $stmt_lib->bind_param("i", $admin_id);
    $stmt_lib->execute();
    $my_lib_id = $stmt_lib->get_result()->fetch_assoc()['library_id'] ?? 0;

    // Only apply filter if a library is actually assigned
    if ($my_lib_id > 0) {
        $library_filter_clause = "tb.library_id = ?";
        $lib_params[] = $my_lib_id;
        $lib_types = "i";
    }
}

// ==========================================
// 1. HANDLE FINE ACTIONS (POST)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $fine_id = (int)($_POST['fine_id'] ?? 0);

    // --- COLLECT FINE ---
    if ($action === 'collect_fine') {
        $payment_method = trim($_POST['payment_method'] ?? '');
        $transaction_id = trim($_POST['transaction_id'] ?? NULL); 
        $current_admin_id = $_SESSION['admin_id'] ?? 0; 
        $paid_on = date('Y-m-d H:i:s');

        // --- [NEW] SECURITY CHECK: Ensure Admin belongs to the same library as the fine ---
        if (!$is_super) {
            $stmt_check = $conn->prepare("
                SELECT tb.library_id, l.library_name
                FROM tbl_fines tf
                JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
                JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                JOIN tbl_books tb ON tbc.book_id = tb.book_id
                LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                WHERE tf.fine_id = ?
            ");
            $stmt_check->bind_param("i", $fine_id);
            $stmt_check->execute();
            $fine_context = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($fine_context && $fine_context['library_id'] != $my_lib_id) {
                $lib_name_display = htmlspecialchars($fine_context['library_name'] ?? 'another library');
                $error = "Access Denied: This fine belongs to <strong>$lib_name_display</strong>. You can only collect fines for your assigned library.";
            }
        }
        // ----------------------------------------------------------------------------------

        // Only proceed if no security error occurred above
        if (empty($error)) {
            if (empty($payment_method)) {
                $error = "Payment method is required.";
            } elseif ($payment_method !== 'Cash' && empty($transaction_id)) {
                $error = "Transaction ID is required for non-cash payments (Card/Online Transfer).";
            } else {
                if (empty($transaction_id)) $transaction_id = NULL;

                $stmt = $conn->prepare("UPDATE tbl_fines SET payment_status = 'Paid', payment_method = ?, transaction_id = ?, paid_on = ?, collected_by_admin_id = ?, is_outstanding = 0 WHERE fine_id = ? AND payment_status = 'Pending'");
                $stmt->bind_param("sssii", $payment_method, $transaction_id, $paid_on, $current_admin_id, $fine_id);
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $message = "Fine ID {$fine_id} collected successfully.";
                    
                    // --- MODIFIED EMAIL LOGIC (PAYMENT) ---
                    $inst_init_set = get_setting($conn, 'institution_initials') ?: 'INS';
                    
                    // Fetch details + Library Initials for ID generation
                    $stmt_details = $conn->prepare("
                        SELECT tm.email, tm.full_name, tf.fine_amount, tf.fine_type,
                               COALESCE(l.library_initials, 'LIB') as lib_init,
                               COALESCE(l.library_name, 'Central Library') as lib_name
                        FROM tbl_fines tf 
                        JOIN tbl_members tm ON tf.member_id = tm.member_id 
                        LEFT JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
                        LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                        LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
                        LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                        WHERE tf.fine_id = ?
                    ");
                    $stmt_details->bind_param("i", $fine_id);
                    $stmt_details->execute();
                    $mail_data = $stmt_details->get_result()->fetch_assoc();

                    if ($mail_data && !empty($mail_data['email'])) {
                        // Format: {INST}/{LIB}/FINE/{ID}
                        $receipt_id = strtoupper($inst_init_set . '/' . $mail_data['lib_init'] . '/FINE/' . $fine_id);
                        $amount = number_format($mail_data['fine_amount'], 2);
                        
                        $subject = "Fine Payment Receipt: " . $receipt_id;
                        $body = "
                            <h3>Payment Confirmation</h3>
                            <p>Dear {$mail_data['full_name']},</p>
                            <p>This email confirms that we have received your payment.</p>
                            <div style='background:#f4f4f4; padding:15px; border-radius:5px; margin:10px 0;'>
                                <p style='margin:5px 0;'><strong>Receipt ID:</strong> {$receipt_id}</p>
                                <p style='margin:5px 0;'><strong>Amount Paid:</strong> {$currency}{$amount}</p>
                                <p style='margin:5px 0;'><strong>Reason:</strong> {$mail_data['fine_type']}</p>
                                <p style='margin:5px 0;'><strong>Payment Method:</strong> {$payment_method}</p>
                                <p style='margin:5px 0;'><strong>Transaction ID:</strong> " . ($transaction_id ? $transaction_id : 'N/A') . "</p>
                            </div>
                            <p>Thank you for clearing your dues.</p>
                        ";
                        // Pass library name for branding
                        send_system_email($mail_data['email'], $mail_data['full_name'], $subject, $body, $mail_data['lib_name']);
                    }
                    $stmt_details->close();
                    // --------------------------------------
                } else {
                    $error = "Error collecting fine or fine was already paid.";
                }
                $stmt->close();
            }
        }
    } 
    // --- CANCEL / ARCHIVE FINE ---
    elseif ($action === 'archive_fine') {
        $cancel_reason = trim($_POST['cancel_reason'] ?? '');
        if (empty($cancel_reason)) {
            $error = "Cancellation reason is required.";
        } else {
            $conn->begin_transaction();
            try {
                // 1. Fetch original fine data
                $stmt_get = $conn->prepare("SELECT * FROM tbl_fines WHERE fine_id = ?");
                $stmt_get->bind_param("i", $fine_id);
                $stmt_get->execute();
                $fine_data = $stmt_get->get_result()->fetch_assoc();
                
                if (!$fine_data) throw new Exception("Fine not found.");

                // 2. Insert into Archive
                // Note: Ensure tbl_archived_fines schema matches this insert
                $stmt_arch = $conn->prepare("
                    INSERT INTO tbl_archived_fines 
                    (fine_id, fine_uid, circulation_id, member_id, fine_type, fine_amount, fine_date, is_outstanding, pay_later_due_date, payment_status, payment_method, transaction_id, paid_on, collected_by_admin_id, archived_by, archive_reason)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                // Handle potential NULLs for source data
                $uid = $fine_data['fine_uid'] ?? 'N/A'; // Assuming fine_uid exists in tbl_fines as per db setup, if not, handle gracefully
                
                $stmt_arch->bind_param(
                    "isiisdsissssssis", 
                    $fine_data['fine_id'], 
                    $uid,
                    $fine_data['circulation_id'],
                    $fine_data['member_id'],
                    $fine_data['fine_type'],
                    $fine_data['fine_amount'],
                    $fine_data['fine_date'],
                    $fine_data['is_outstanding'],
                    $fine_data['pay_later_due_date'],
                    $fine_data['payment_status'],
                    $fine_data['payment_method'],
                    $fine_data['transaction_id'],
                    $fine_data['paid_on'],
                    $fine_data['collected_by_admin_id'],
                    $admin_id, // Archived by current admin
                    $cancel_reason
                );
                
                if (!$stmt_arch->execute()) throw new Exception("Failed to archive: " . $stmt_arch->error);

                // ... (Previous code for inserting into archive) ...

                // --- START MODIFICATION: FETCH INFO FOR CANCELLATION EMAIL ---
                $inst_init_code = get_setting($conn, 'institution_initials') ?: 'INS';
                $def_lib_init = get_setting($conn, 'library_initials') ?: 'LIB';
                $def_lib_name = get_setting($conn, 'library_name') ?: 'Library';

                // Fetch member email and library details associated with this fine
                $stmt_cancel_mail = $conn->prepare("
                    SELECT tm.email, tm.full_name, 
                           COALESCE(l.library_initials, ?) as lib_init,
                           COALESCE(l.library_name, ?) as lib_name
                    FROM tbl_fines tf
                    JOIN tbl_members tm ON tf.member_id = tm.member_id
                    LEFT JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
                    LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                    LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
                    LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                    WHERE tf.fine_id = ?
                ");
                $stmt_cancel_mail->bind_param("ssi", $def_lib_init, $def_lib_name, $fine_id);
                $stmt_cancel_mail->execute();
                $cancel_mail_data = $stmt_cancel_mail->get_result()->fetch_assoc();
                $stmt_cancel_mail->close();
                // ---------------------------------------------------------

                // 3. Delete from Active Fines
                $stmt_del = $conn->prepare("DELETE FROM tbl_fines WHERE fine_id = ?");
                $stmt_del->bind_param("i", $fine_id);
                if (!$stmt_del->execute()) throw new Exception("Failed to delete original record.");

                $conn->commit();
                
                // --- SEND CANCELLATION EMAIL ---
                if ($cancel_mail_data && !empty($cancel_mail_data['email'])) {
                    // Format: {institution initial}/{fine created library initial}/FINE/{fine id}
                    $formatted_fine_id = strtoupper($inst_init_code . '/' . $cancel_mail_data['lib_init'] . '/FINE/' . $fine_id);
                    $formatted_amount = number_format($fine_data['fine_amount'], 2);

                    $subject = "Fine Cancelled: " . $formatted_fine_id;
                    $body = "
                        <h2 style='color: #64748b;'>Fine Cancelled</h2>
                        <p>Dear {$cancel_mail_data['full_name']},</p>
                        <p>Please be informed that the following fine has been <strong>cancelled/waived</strong>.</p>
                        
                        <div style='background:#fff0f0; border:1px solid #fecaca; padding:15px; border-radius:8px; margin:15px 0;'>
                            <p><strong>Fine ID:</strong> {$formatted_fine_id}</p>
                            <p><strong>Original Amount:</strong> {$currency}{$formatted_amount}</p>
                            <p><strong>Fine Reason:</strong> {$fine_data['fine_type']}</p>
                            <hr style='border:0; border-top:1px dashed #fecaca; margin:10px 0;'>
                            <p style='color:#b91c1c;'><strong>Reason for Cancellation:</strong> {$cancel_reason}</p>
                        </div>
                        <p>No further action is required regarding this specific fine.</p>
                    ";
                    
                    send_system_email($cancel_mail_data['email'], $cancel_mail_data['full_name'], $subject, $body, $cancel_mail_data['lib_name']);
                }
                // --- END MODIFICATION ---

                $message = "Fine ID #{$fine_id} has been cancelled and archived.";

            } catch (Exception $e) {
                $conn->rollback();
                $error = "Cancellation failed: " . $e->getMessage();
            }
        }
    }
    // --- CREATE MANUAL FINE ACTION ---
    elseif ($action === 'create_manual_fine') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        
        // [FIX START] Fallback: If hidden ID is empty, try to find member by the visible UID input
        if ($member_id === 0 && !empty($_POST['member_uid'])) {
            $raw_uid_input = trim($_POST['member_uid']);
            $search_term = $raw_uid_input;
            
            // Extract UID if format is "Name (UID)" (from autocomplete)
            if (preg_match('/\(([^)]+)\)$/', $raw_uid_input, $matches)) {
                $search_term = $matches[1];
            }
            
            // Look up the ID manually
            $stmt_lookup = $conn->prepare("SELECT member_id FROM tbl_members WHERE member_uid = ? LIMIT 1");
            $stmt_lookup->bind_param("s", $search_term);
            $stmt_lookup->execute();
            $res_lookup = $stmt_lookup->get_result();
            if ($row_lookup = $res_lookup->fetch_assoc()) {
                $member_id = $row_lookup['member_id'];
            }
            $stmt_lookup->close();
        }
        // [FIX END]

        $fine_type_base = trim($_POST['manual_fine_type'] ?? ''); 
        $custom_fine_type = trim($_POST['custom_fine_type'] ?? ''); 
        $damaged_fine_amount = (float)($_POST['damaged_fine_amount'] ?? 0); 
        $total_fine_amount = (float)($_POST['total_fine_amount'] ?? 0);
        
        $fine_type = $fine_type_base;
        if ($fine_type_base === 'Other' && !empty($custom_fine_type)) {
             $fine_type = $custom_fine_type;
        }

        $issued_book_circ_id = (int)($_POST['issued_book_circulation_id'] ?? 0); 
        $fallback_circ_id = (int)($_POST['fallback_circulation_id'] ?? 0); 

        // Validation
        if ($member_id === 0 || empty($fine_type_base) || $damaged_fine_amount <= 0) {
            $error = "Member ID, Fine Reason, and Amount (>0) are required. (Please ensure the Member ID is valid)";
        } elseif ($fine_type_base === 'Other' && empty($custom_fine_type)) {
             $error = "The 'Other' reason must be specified.";
        } elseif ($fine_type_base === 'Damaged Book' && $issued_book_circ_id === 0) {
             $error = "A currently issued book must be selected for 'Damaged Book' fine type.";
        } else {
            $conn->begin_transaction();
            try {
                // --- DETERMINE FINAL circulation_id ---
                $final_circulation_id = 0;
                
                if ($fine_type_base === 'Damaged Book') {
                    $final_circulation_id = $issued_book_circ_id;
                    if ($total_fine_amount > $damaged_fine_amount) {
                         $fine_type = "Damaged Book + Late Fee";
                    }
                } else {
                    $final_circulation_id = $fallback_circ_id;
                }
                
                if ($final_circulation_id === 0) {
                     $res_fallback = $conn->query("SELECT circulation_id FROM tbl_circulation WHERE status = 'Returned' ORDER BY circulation_id DESC LIMIT 1");
                     if ($res_fallback->num_rows > 0) {
                         $final_circulation_id = $res_fallback->fetch_assoc()['circulation_id'];
                     } else {
                         throw new Exception("Cannot create fine: No circulation records exist in the system to link the fine to.");
                     }
                }
                
                $fine_date = date('Y-m-d');
                $is_outstanding = 1; 
                
                // Generate Fine UID (if applicable, reusing function logic or assuming auto-increment ID is enough for now, but table has fine_uid)
                $fine_uid = generate_fine_uid($conn);

                $stmt = $conn->prepare("INSERT INTO tbl_fines (fine_uid, circulation_id, member_id, fine_type, fine_amount, fine_date, is_outstanding) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("siisdsi", $fine_uid, $final_circulation_id, $member_id, $fine_type, $total_fine_amount, $fine_date, $is_outstanding);
                
                if (!$stmt->execute()) {
                    throw new Exception($conn->error);
                }
                $new_fine_id = $conn->insert_id;

                // --- Update book status if it was a Damaged Book fine ---
                if ($fine_type_base === 'Damaged Book' && $issued_book_circ_id > 0) {
                     $stmt_circ_data = $conn->prepare("SELECT tc.copy_id, tbc.book_id FROM tbl_circulation tc JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id WHERE tc.circulation_id = ? AND tc.status = 'Issued'");
                     $stmt_circ_data->bind_param("i", $final_circulation_id);
                     $stmt_circ_data->execute();
                     $circ_data = $stmt_circ_data->get_result()->fetch_assoc();
                     
                     if ($circ_data) {
                         $stmt_upd_circ = $conn->prepare("UPDATE tbl_circulation SET status = 'Returned', return_date = ? WHERE circulation_id = ?");
                         $stmt_upd_circ->bind_param("si", $fine_date, $final_circulation_id);
                         if (!$stmt_upd_circ->execute()) throw new Exception($conn->error);

                         $stmt_upd_copy = $conn->prepare("UPDATE tbl_book_copies SET status = 'Available' WHERE copy_id = ?");
                         $stmt_upd_copy->bind_param("i", $circ_data['copy_id']);
                         if (!$stmt_upd_copy->execute()) throw new Exception($conn->error);

                         $stmt_upd_book = $conn->prepare("UPDATE tbl_books SET available_quantity = LEAST(total_quantity, available_quantity + 1) WHERE book_id = ?");
                         $stmt_upd_book->bind_param("i", $circ_data['book_id']);
                         if (!$stmt_upd_book->execute()) throw new Exception($conn->error);
                     }
                }
                
                $conn->commit();

                // --- START MODIFICATION: SEND EMAIL ON MANUAL FINE CREATION ---
                // 1. Fetch details needed for email (Member email + Library Initials)
                $inst_init_code = get_setting($conn, 'institution_initials') ?: 'INS';
                
                // Logic to find Library Initials:
                // If linked to a book ($final_circulation_id), get that library. 
                // If generic/other, get system default library.
                $sql_email_data = "
                    SELECT tm.email, tm.full_name, 
                           COALESCE(l.library_initials, ?) AS lib_init,
                           COALESCE(l.library_name, ?) AS lib_name
                    FROM tbl_members tm
                    LEFT JOIN tbl_circulation tc ON tc.circulation_id = ?
                    LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                    LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
                    LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                    WHERE tm.member_id = ?
                ";
                
                $default_lib_init = get_setting($conn, 'library_initials') ?: 'LIB';
                $default_lib_name = get_setting($conn, 'library_name') ?: 'Library';
                
                $stmt_email = $conn->prepare($sql_email_data);
                $stmt_email->bind_param("ssii", $default_lib_init, $default_lib_name, $final_circulation_id, $member_id);
                $stmt_email->execute();
                $email_res = $stmt_email->get_result()->fetch_assoc();
                
                if ($email_res && !empty($email_res['email'])) {
                    // Generate ID Format: {INST}/{LIB}/FINE/{ID}
                    $formatted_fine_id = strtoupper($inst_init_code . '/' . $email_res['lib_init'] . '/FINE/' . $new_fine_id);
                    $formatted_amount = number_format($total_fine_amount, 2);
                    
                    $subject = "New Fine Added: " . $formatted_fine_id;
                    $body = "
                        <h3>Notice of Fine</h3>
                        <p>Dear {$email_res['full_name']},</p>
                        <p>A fine has been added to your account.</p>
                        <table style='width:100%; border-collapse:collapse; margin:15px 0;'>
                            <tr><td style='padding:5px; font-weight:bold;'>Fine ID:</td><td style='padding:5px;'>{$formatted_fine_id}</td></tr>
                            <tr><td style='padding:5px; font-weight:bold;'>Reason:</td><td style='padding:5px;'>{$fine_type}</td></tr>
                            <tr><td style='padding:5px; font-weight:bold;'>Amount:</td><td style='padding:5px; color:red;'>{$currency}{$formatted_amount}</td></tr>
                            <tr><td style='padding:5px; font-weight:bold;'>Date:</td><td style='padding:5px;'>{$fine_date}</td></tr>
                        </table>
                        <p>Please visit <strong>{$email_res['lib_name']}</strong> to clear these dues.</p>
                    ";
                    
                    send_system_email($email_res['email'], $email_res['full_name'], $subject, $body, $email_res['lib_name']);
                }
                // --- END MODIFICATION ---
                
                // --- PREPARE DATA FOR SUCCESS MODAL ---
                // Fetch fresh details for the modal
                $stmt_fetch = $conn->prepare("SELECT tm.full_name, tm.member_uid FROM tbl_members tm WHERE tm.member_id = ?");
                $stmt_fetch->bind_param("i", $member_id);
                $stmt_fetch->execute();
                $m_data = $stmt_fetch->get_result()->fetch_assoc();
                
                $_SESSION['new_manual_fine'] = [
                    'id' => $new_fine_id,
                    'amount' => $total_fine_amount,
                    'type' => $fine_type,
                    'member_name' => $m_data['full_name'],
                    'member_uid' => $m_data['member_uid']
                ];
                
                redirect('fines.php'); 
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Fine creation failed: " . $e->getMessage();
                redirect('fines.php'); 
            }
        }
    }
}

// --- CHECK SESSION FOR FLASH MESSAGES OR NEW FINE DATA ---
if (isset($_SESSION['flash_success'])) {
    $message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}
$new_fine_data = null;
if (isset($_SESSION['new_manual_fine'])) {
    $new_fine_data = $_SESSION['new_manual_fine'];
    unset($_SESSION['new_manual_fine']);
}

// ==========================================
// 2. FETCH OUTSTANDING FINES (Active)
// ==========================================
$sql_pending = "
    SELECT 
        tf.fine_id, tf.fine_amount, tf.fine_date, tf.payment_status, tf.fine_type,
        tm.full_name AS member_name, tm.member_uid,
        tb.title AS book_title, tb.library_id,
        tbc.book_uid,
        l.library_name, l.library_initials
    FROM tbl_fines tf
    JOIN tbl_members tm ON tf.member_id = tm.member_id
    JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
    LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
";

$pending_where = ["tf.payment_status = 'Pending'", "tf.is_outstanding = 1"];
$pending_params = [];
$pending_types = '';

if (!empty($search_query)) {
    $pending_where[] = "(tf.fine_id = ? OR tm.full_name LIKE ? OR tm.member_uid LIKE ? OR tb.title LIKE ? OR tbc.book_uid LIKE ?)";
    $term_like = "%" . $search_query . "%";
    $pending_params = [$search_query, $term_like, $term_like, $term_like, $term_like];
    $pending_types = 'sssss';
}

// Add Library Filter
if (!empty($library_filter_clause)) {
    $pending_where[] = $library_filter_clause;
    $pending_params = array_merge($pending_params, $lib_params);
    $pending_types .= $lib_types;
}

$sql_pending .= " WHERE " . implode(' AND ', $pending_where) . " ORDER BY tf.fine_date ASC";

$stmt_pending = $conn->prepare($sql_pending);
if (!empty($pending_params)) {
    $stmt_pending->bind_param($pending_types, ...$pending_params);
}
$stmt_pending->execute();
$pending_fines_result = $stmt_pending->get_result();
$stmt_pending->close();


// ==========================================
// 3. FETCH PAID FINES (History) with Pagination
// ==========================================
$page_no = (int)($_GET['page_no'] ?? 1);
$total_records_per_page = 10; 
$offset = ($page_no - 1) * $total_records_per_page;
$previous_page = $page_no - 1;
$next_page = $page_no + 1;

$base_joins = "
    FROM tbl_fines tf
    JOIN tbl_members tm ON tf.member_id = tm.member_id
    JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
    LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
";

$paid_where = ["tf.payment_status = 'Paid'"];
$paid_params = [];
$paid_types = '';

if (!empty($search_query)) {
    $paid_where[] = "(tf.fine_id = ? OR tm.full_name LIKE ? OR tm.member_uid LIKE ? OR tf.payment_method LIKE ? OR tf.transaction_id LIKE ? OR tb.title LIKE ? OR tbc.book_uid LIKE ?)";
    $term_like = "%" . $search_query . "%";
    $paid_params = [$search_query, $term_like, $term_like, $term_like, $term_like, $term_like, $term_like];
    $paid_types = 'sssssss';
}

// Add Library Filter
if (!empty($library_filter_clause)) {
    $paid_where[] = $library_filter_clause;
    $paid_params = array_merge($paid_params, $lib_params);
    $paid_types .= $lib_types;
}

$paid_where_clause = " WHERE " . implode(' AND ', $paid_where);

// --- Count Total Records for Pagination ---
$sql_count = "SELECT COUNT(*) As total_records " . $base_joins . $paid_where_clause;
$stmt_count = $conn->prepare($sql_count);

if (!empty($paid_params)) {
    $count_types = substr($paid_types, 0, strlen($paid_types)); 
    $stmt_count->bind_param($count_types, ...$paid_params);
}
$stmt_count->execute();
$total_records = $stmt_count->get_result()->fetch_assoc()['total_records'];
$total_no_of_pages = ceil($total_records / $total_records_per_page);
$stmt_count->close();

// --- Fetch Paginated Data ---
$sql_paid = "SELECT 
        tf.fine_id, tf.fine_amount, tf.paid_on, tf.payment_method, tf.transaction_id, tf.fine_type,
        tm.full_name AS member_name, tm.member_uid,
        tb.title AS book_title,
        tbc.book_uid,
        l.library_initials " . $base_joins . $paid_where_clause . " ORDER BY tf.paid_on DESC LIMIT ?, ?";

// Add LIMIT/OFFSET parameters
$paid_params_data = $paid_params;
$paid_params_data[] = $offset;
$paid_params_data[] = $total_records_per_page;
$paid_types_data = $paid_types . "ii";

$stmt_paid = $conn->prepare($sql_paid);
if (!empty($paid_params_data)) {
    $stmt_paid->bind_param($paid_types_data, ...$paid_params_data);
}
$stmt_paid->execute();
$paid_fines_result = $stmt_paid->get_result();
$stmt_paid->close();


admin_header('Fine Management');

?>

<style>
    /* Global Styles for Deep Black Text */
    :root {
        --primary-text-color: #111827; /* Deep Black/Dark Gray */
        --secondary-text-color: #4b5563;
        --border-color: rgba(0, 0, 0, 0.1);
        --accent-color: #3b82f6;
        --success-color: #10b981;
        --danger-color: #ef4444;
        --warning-color: #f59e0b;
    }

    /* Glass Components */
    .glass-card {
        background: rgba(255, 255, 255, 0.9); /* More opaque white */
        backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
        border: 1px solid rgba(255, 255, 255, 0.8);
        box-shadow: 0 4px 15px 0 rgba(0, 0, 0, 0.1); 
        border-radius: 20px; padding: 30px; margin-bottom: 30px;
        animation: fadeIn 0.5s ease-out;
    }

    .card-header {
        display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;
        border-bottom: 1px solid var(--border-color); padding-bottom: 15px;
    }
    .card-header h2 { margin: 0; color: var(--primary-text-color); font-size: 1.6rem; display: flex; align-items: center; gap: 10px; }
    
    /* Search Bar */
    .search-container { display: flex; gap: 10px; }
    .form-control {
        padding: 10px 15px; border: 1px solid rgba(0,0,0,0.2); 
        background: rgba(255,255,255,0.9);
        border-radius: 10px; width: 250px; transition: 0.3s;
        color: var(--primary-text-color); 
    }
    .form-control:focus { background: #fff; border-color: var(--accent-color); outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2); }

    /* Buttons */
    .btn-main {
        background: var(--accent-color); color: white; border: none; padding: 10px 20px; border-radius: 10px; cursor: pointer; font-weight: 600; transition: 0.2s;
    }
    .btn-main:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4); }
    
    .btn-collect {
        background: var(--success-color); color: white; padding: 6px 12px; border-radius: 8px; border: none; font-size: 0.85rem; cursor: pointer; font-weight: 600;
    }
    .btn-collect:hover { background: #059669; box-shadow: 0 2px 5px rgba(16,185,129,0.3); }

    .btn-cancel-fine {
        background: #cbd5e1; color: #334155; padding: 6px 12px; border-radius: 8px; border: none; font-size: 0.85rem; cursor: pointer; font-weight: 600; margin-left: 5px;
    }
    .btn-cancel-fine:hover { background: #94a3b8; color: #1e293b; }

    .btn-receipt {
        background: #4b5563; 
        color: white; padding: 6px 12px; border-radius: 8px; border: none; font-size: 0.85rem; cursor: pointer; text-decoration: none; display: inline-block;
    }
    .btn-receipt:hover { background: #374151; }

    /* Table */
    .table-container { overflow-x: auto; border-radius: 12px; }
    .glass-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    .glass-table th { 
        background: rgba(59, 130, 246, 0.1); 
        color: var(--primary-text-color); 
        font-weight: 700; padding: 15px; text-align: left; font-size: 0.85rem; text-transform: uppercase; 
    }
    .glass-table td { 
        padding: 15px; border-bottom: 1px solid rgba(0,0,0,0.08); 
        color: var(--secondary-text-color); 
        font-size: 0.95rem; vertical-align: middle; 
    }
    .glass-table tr:hover { background: rgba(0, 0, 0, 0.03); }
    .glass-table td strong { color: var(--primary-text-color); }

    /* Badges */
    .status-badge { padding: 4px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
    .status-pending { background: #fee2e2; color: #991b1b; }
    .status-paid { background: #dcfce7; color: #166534; }
    .type-badge { background: #e0f2fe; color: #1d4ed8; padding: 3px 8px; border-radius: 6px; font-size: 0.8rem; }

    /* Modal */
    .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(5px); justify-content: center; align-items: center; }
    .modal-content { background: #fff; padding: 30px; border-radius: 20px; width: 90%; max-width: 450px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); animation: scaleUp 0.3s ease-out; position: relative; }
    .close-btn { position: absolute; right: 20px; top: 20px; font-size: 24px; cursor: pointer; color: #94a3b8; }
    .modal-input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; color: var(--primary-text-color); }
    
    .modal-btn { width: 100%; padding: 12px; background: var(--success-color); color: white; border: none; border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 1rem; margin-top: 10px; }
    .modal-btn:hover { background: #059669; }
    
    .modal-btn-danger { background: var(--danger-color); }
    .modal-btn-danger:hover { background: #b91c1c; }

    /* Pagination */
    .pagination { display: flex; gap: 5px; list-style: none; justify-content: center; margin-top: 20px; padding: 0; }
    .page-link { padding: 8px 14px; border-radius: 8px; background: rgba(255,255,255,0.5); color: #64748b; text-decoration: none; font-size: 0.9rem; transition: 0.2s; }
    .page-link:hover { background: #fff; color: var(--primary-text-color); }
    .page-link.active { background: var(--accent-color); color: white; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3); }

    /* Alerts */
    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* Transaction ID Tag */
    .monospace-tag { 
        font-size: 0.75rem !important; 
        color: var(--primary-text-color) !important; 
        background: rgba(0, 0, 0, 0.05) !important; 
        padding: 2px 6px; border-radius: 4px; display: inline-block; margin-top: 4px; 
        font-family: monospace; 
        border: 1px solid rgba(0,0,0,0.1) !important;
    }
    
    /* NEW FINE CREATION STYLES */
    .fine-creator-card {
        border-top: 6px solid var(--warning-color);
        margin-bottom: 30px;
    }

    .fine-creator-card h3 {
        color: var(--warning-color);
        font-size: 1.2rem;
        margin-bottom: 15px;
    }

    .member-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 20px;
    }

    .member-detail-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 10px;
        border-radius: 8px;
        min-height: 58px; /* Ensure boxes align vertically */
    }

    .member-detail-box label {
        font-size: 0.8rem;
        color: #64748b;
        font-weight: 500;
        margin-bottom: 2px;
        display: block;
    }

    .member-detail-box span {
        font-weight: 600;
        color: var(--primary-text-color);
        display: block;
    }
    
    .input-and-suggestions {
        position: relative;
        width: 50%;
    }

    .autocomplete-list {
        position: absolute;
        width: 100%;
        max-height: 200px;
        overflow-y: auto;
        background: white;
        border: 1px solid #d1d5db;
        border-top: none;
        border-radius: 0 0 10px 10px;
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
    
    /* NEW: Fine Breakdown Styles */
    .fine-breakdown-box {
        background: #fff;
        border: 1px solid #fcd34d;
        color: #92400e;
        padding: 15px;
        border-radius: 8px;
        margin-top: 15px;
        display: none;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .fine-breakdown-row {
        display: flex;
        justify-content: space-between;
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    .fine-breakdown-row.total {
        font-size: 1.1rem;
        font-weight: 700;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid #fef08a;
        color: var(--danger-color);
    }
    .fine-breakdown-row.total span { color: #111827; }
    .fine-breakdown-row.total .value { color: var(--danger-color); }
    
    .fine-breakdown-box .info { color: #f59e0b; }

    /* Confirmation Grid */
    .conf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
    .conf-item { background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; }
    .conf-label { font-size: 0.75rem; color: #64748b; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 4px; }
    .conf-value { font-size: 0.95rem; color: #1e293b; font-weight: 600; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes scaleUp { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
    
    #loadingOverlay {
        display: none; /* [FIXED] Removed !important to allow JS toggling */
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        /* Background and Blur for the blocked state */
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(4px); 
        -webkit-backdrop-filter: blur(4px);
        z-index: 99999; /* Extremely high Z-Index */
        
        /* Centering the spinner */
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }
    .loader {
        border: 5px solid #f3f3f3;
        border-top: 5px solid var(--accent-color, #3b82f6); /* Using CSS Variable or fallback */
        border-radius: 50%;
        width: 50px;
        height: 50px;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }



</style>

<?php if ($message): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> <?php endif; ?>
<?php if ($error): ?> <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> <?php endif; ?>

<div class="glass-card fine-creator-card">
    <div class="card-header" style="border-bottom: none; margin-bottom: 10px;">
        <h2><i class="fas fa-file-invoice" style="color: var(--warning-color);"></i> Create Manual Fine</h2>
        <button type="button" class="btn-main" onclick="toggleFineForm(this)" style="background: var(--warning-color); width: 150px; padding: 10px 15px;"><i class="fas fa-plus"></i> Open Form</button>
    </div>
    
    <div id="fineFormContent" style="display: none; padding-top: 20px; border-top: 1px solid var(--border-color);">
        <form method="POST">
            <input type="hidden" name="action" value="create_manual_fine">
            <input type="hidden" id="member_id_input" name="member_id">
            <input type="hidden" id="fallback_circulation_id" name="fallback_circulation_id">
            <input type="hidden" id="total_fine_amount_input" name="total_fine_amount">

            <div class="member-info-grid" style="grid-template-columns: 1fr;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label>Member ID (UID)</label>
                    <div class="input-and-suggestions">
                        <input type="text" id="member_uid_input" name="member_uid" class="form-control" placeholder="Enter Member ID (e.g., ABC/DEF/YY/AAA)" required autocomplete="off" oninput="fetchSuggestions('member', this)" style="width: 100%;" onblur="fetchMemberDetails()">
                        <div id="member_uid_input_suggestions" class="autocomplete-list" style="display: none;"></div>
                    </div>
                </div>
            </div>
            
            <div id="member_details_container" class="member-info-grid" style="display: none;">
                <div class="member-detail-box">
                    <label>Full Name</label>
                    <span id="member_full_name_display">N/A</span>
                </div>
                <div class="member-detail-box">
                    <label>Department</label>
                    <span id="member_department_display">N/A</span>
                </div>
                <div class="member-detail-box">
                    <label>Email</label>
                    <span id="member_email_display">N/A</span>
                </div>
                <div class="member-detail-box">
                    <label>Account Status</label>
                    <span id="member_status_display" class="status-badge status-pending">N/A</span>
                </div>
                <div class="member-detail-box" style="grid-column: 1 / -1;">
                    <label>Screenshot Violations (Security Risk)</label>
                    <span id="member_violations_display">0</span>
                </div>
            </div>
            <br>
            <div class="form-group">
                    <label>Fine Reason</label>
                    <select id="fine_reason" name="manual_fine_type" class="form-control" required style="width: 100%; padding-left: 15px;" onchange="toggleFineReasonFields()">
                        <option value="">-- Select or Enter Reason --</option>
                        <option value="Violations (Security Risk)">Violations (Security Risk)</option>
                        <option value="Damaged Book">Damaged Book</option>
                        <option value="Misconduct">Library Misconduct</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <br>
                <div id="other_reason_group" class="form-group" style="display: none; grid-column: 1 / -1;">
                <label>Specify Other Reason</label>
                <input type="text" id="custom_fine_type" name="custom_fine_type" class="form-control" placeholder="e.g. Unauthorised access" style="width: 100%; padding-left: 15px;">
                 </div>
<br>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                 <div class="form-group">
                    <label>Fine Amount (Damaged/Base Fee)</label>
                    <input type="number" step="0.01" id="damaged_fine_amount" name="damaged_fine_amount" class="form-control" required min="0.01" style="width: 50%;" oninput="updateFineBreakdown()">
                </div>
            </div>
            
            <div id="issued_book_group" class="form-group" style="display: none; grid-column: 1 / -1;">
                <label>Select Issued Book (Mandatory for Damaged Fine)</label>
                <select id="issued_book_select" name="issued_book_circulation_id" class="form-control" style="width: 100%; padding-left: 15px;" onchange="updateFineBreakdown()">
                    <option value="">-- No Books Currently Issued --</option>
                </select>
                
                <div id="fine_breakdown" class="fine-breakdown-box">
                    <div class="fine-breakdown-row info">
                        <span>Book Due Date:</span>
                        <strong id="due_date_display">N/A</strong>
                    </div>
                    <div class="fine-breakdown-row">
                        <span>Overdue Days:</span>
                        <span id="overdue_days_display" class="value">0 Days</span>
                    </div>
                    <div class="fine-breakdown-row">
                        <span>Late Return Fine (<?php echo $currency . number_format($fine_per_day, 2); ?>/day):</span>
                        <span id="late_fine_display" class="value"><?php echo $currency; ?>0.00</span>
                    </div>
                    <div class="fine-breakdown-row">
                        <span>Damaged Book Fine (Base Fee):</span>
                        <span id="base_fine_display" class="value"><?php echo $currency; ?>0.00</span>
                    </div>
                    <div class="fine-breakdown-row total">
                        <span style="color: #111827;">TOTAL FINE:</span>
                        <span id="total_fine_display" class="value"><?php echo $currency; ?>0.00</span>
                    </div>
                </div>
                
            </div>

            <button type="submit" class="btn-main" style="background: var(--success-color); margin-top: 10px;">
                <i class="fas fa-check"></i> Save Outstanding Fine
            </button>
        </form>
    </div>
</div>
<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-exclamation-circle" style="color: var(--danger-color);"></i> Outstanding Fines</h2>
        <form method="GET" class="search-container">
            <input type="search" name="search" class="form-control" placeholder="Search fines..." value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit" class="btn-main"><i class="fas fa-search"></i></button>
            <?php if (!empty($search_query)): ?> <a href="fines.php" class="btn-main" style="background: var(--danger-color);"><i class="fas fa-times"></i></a> <?php endif; ?>
        </form>
    </div>

    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Fine ID</th>
                    <th>Member</th>
                    <th>Book Details</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($pending_fines_result->num_rows > 0): ?>
                    <?php while ($fine = $pending_fines_result->fetch_assoc()): 
                        $lib_init = $fine['library_initials'] ?: 'LIB';
                        $receipt_no = strtoupper($inst_init . '/' . $lib_init . '/FINE/' . $fine['fine_id']);
                    ?>
                        <tr>
                            <td><strong style="font-family:monospace; color:#4f46e5; font-size: 0.9rem;"><?php echo $receipt_no; ?></strong></td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($fine['member_name']); ?></div>
                                <div class="monospace-tag">
                                    <?php echo htmlspecialchars($fine['member_uid']); ?>
                                </div>
                            </td>
                            <td>
                                <div style="color: var(--primary-text-color);"><?php echo htmlspecialchars($fine['book_title'] ?? 'N/A (Manual Fine)'); ?></div>
                                <div style="font-size: 0.8rem; color: #94a3af;">UID: <?php echo htmlspecialchars($fine['book_uid'] ?? 'N/A'); ?></div>
                                <?php if(isset($fine['library_name'])): ?>
                                    <div style="font-size: 0.75rem; color: #3b82f6; margin-top: 2px;">
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($fine['library_name']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="type-badge"><?php echo htmlspecialchars($fine['fine_type']); ?></span></td>
                            <td style="color: var(--danger-color); font-weight: 700;"><?php echo $currency . number_format($fine['fine_amount'], 2); ?></td>
                            <td><span class="status-badge status-pending">Unpaid</span></td>
                            <td>
                                <button class="btn-collect collect-fine-btn" 
                                    data-id="<?php echo $fine['fine_id']; ?>" 
                                    data-member="<?php echo htmlspecialchars($fine['member_name']); ?>" 
                                    data-amount="<?php echo number_format($fine['fine_amount'], 2); ?>"
                                    data-currency="<?php echo $currency; ?>">
                                    <i class="fas fa-hand-holding-usd"></i> Pay Now
                                </button>
                                <button class="btn-cancel-fine" 
                                    onclick="openCancelModal(
                                        <?php echo $fine['fine_id']; ?>, 
                                        '<?php echo addslashes($fine['member_name']); ?>', 
                                        '<?php echo $currency . number_format($fine['fine_amount'], 2); ?>',
                                        '<?php echo addslashes($fine['fine_type']); ?>'
                                    )"
                                    title="Cancel/Revert Fine">
                                    <i class="fas fa-undo-alt"></i> Cancel
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 30px; color: var(--secondary-text-color);">No outstanding fines found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-receipt" style="color: var(--success-color);"></i> Paid History</h2>
    </div>
    
    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Receipt ID</th>
                    <th>Member</th>
                    <th>Book</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Paid Date</th>
                    <th>Method</th>
                    <th>Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($paid_fines_result->num_rows > 0): ?>
                    <?php while ($fine = $paid_fines_result->fetch_assoc()): 
                        $lib_init = $fine['library_initials'] ?: 'LIB';
                        $receipt_no = strtoupper($inst_init . '/' . $lib_init . '/FINE/' . $fine['fine_id']);
                    ?>
                        <tr>
                            <td><strong style="font-family:monospace; color:#4f46e5; font-size: 0.9rem;"><?php echo $receipt_no; ?></strong></td>
                            <td><?php echo htmlspecialchars($fine['member_name']); ?></td>
                            <td><?php echo htmlspecialchars($fine['book_title'] ?? 'N/A (Manual Fine)'); ?></td>
                            <td><span class="type-badge"><?php echo htmlspecialchars($fine['fine_type']); ?></span></td>
                            <td style="font-weight: 600; color: var(--success-color);"><?php echo $currency . number_format($fine['fine_amount'], 2); ?></td>
                            <td><?php echo date('d M Y', strtotime($fine['paid_on'])); ?></td>
                            <td>
                                <div style="font-weight: 600; color: var(--primary-text-color);"><?php echo htmlspecialchars($fine['payment_method']); ?></div>
                                <?php if($fine['transaction_id']): ?>
                                    <div class="monospace-tag">
                                        Ref: <?php echo htmlspecialchars($fine['transaction_id']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="generate_receipt.php?fine_id=<?php echo $fine['fine_id']; ?>" target="_blank" class="btn-receipt"><i class="fas fa-print"></i> Print</a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="8" style="text-align: center; padding: 30px; color: var(--secondary-text-color);">No paid fines history found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-color);">
        <div style="font-size: 0.9rem; color: var(--secondary-text-color);">
            Showing Page <strong><?php echo $page_no; ?></strong> of <?php echo $total_no_of_pages; ?>
        </div>
        <ul class="pagination">
            <?php if($page_no > 1): ?>
                <li><a href="?page_no=<?php echo $previous_page; ?>&search=<?php echo urlencode($search_query); ?>" class="page-link"><i class="fas fa-chevron-left"></i> Prev</a></li>
            <?php endif; ?>
            
            <?php 
            $start = max(1, $page_no - 2);
            $end = min($total_no_of_pages, $page_no + 2);

            if ($start > 1) { echo '<li><span class="page-link" style="pointer-events: none;">...</span></li>'; }

            for ($i = $start; $i <= $end; $i++): 
                $active_class = ($i == $page_no) ? ' active' : '';
            ?>
                <li><a href="?page_no=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>" class="page-link<?php echo $active_class; ?>"><?php echo $i; ?></a></li>
            <?php endfor; ?>

            <?php if ($end < $total_no_of_pages) { echo '<li><span class="page-link" style="pointer-events: none;">...</span></li>'; } ?>

            <?php if($page_no < $total_no_of_pages): ?>
                <li><a href="?page_no=<?php echo $next_page; ?>&search=<?php echo urlencode($search_query); ?>" class="page-link">Next <i class="fas fa-chevron-right"></i></a></li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<div id="collectFineModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="document.getElementById('collectFineModal').style.display='none'">&times;</span>
        <h3><i class="fas fa-coins" style="color: #f59e0b;"></i> Collect Payment</h3>
        
        <div style="background: #f8fafc; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
            <p style="margin: 5px 0; color: var(--secondary-text-color);">Member: <strong style="color: var(--primary-text-color);" id="modal_member_name"></strong></p>
            <p style="margin: 5px 0; color: var(--secondary-text-color);">Total Due: <strong style="color: var(--danger-color); font-size: 1.1rem;" id="modal_fine_amount"></strong></p>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="collect_fine">
            <input type="hidden" id="collect_fine_id" name="fine_id">
            
            <div class="form-group">
                <label>Payment Method</label>
                <select id="payment_method" name="payment_method" class="modal-input" required onchange="toggleTransactionId(this.value)">
                    <option value="">-- Select Method --</option>
                    <option value="Cash">Cash</option>
                    <option value="Card">Card</option>
                    <option value="Online Transfer">Online Transfer (UPI/Bank)</option>
                </select>
            </div>
            
            <div class="form-group" id="transaction_id_group" style="display:none;">
                <label>Transaction Ref ID</label>
                <input type="text" id="transaction_id" name="transaction_id" class="modal-input" placeholder="e.g. TXN-123456">
            </div>
            
            <button type="submit" class="modal-btn"><i class="fas fa-check-circle"></i> Confirm Payment</button>
        </form>
    </div>
</div>

<?php if (isset($new_fine_data)): ?>
<div id="successModal" class="modal" style="display: flex;">
    <div class="modal-content" style="text-align: center;">
        <i class="fas fa-check-circle" style="font-size: 4rem; color: var(--success-color); margin-bottom: 15px;"></i>
        <h3 style="margin: 0 0 10px 0; color: #111827;">Fine Created Successfully</h3>
        <p style="color: #64748b; margin-bottom: 20px;">A manual fine has been recorded.</p>
        
        <div class="conf-grid" style="text-align: left; background: #f0fdf4; padding: 15px; border: 1px solid #bbf7d0; border-radius: 12px;">
            <div class="conf-item" style="background: white;">
                <span class="conf-label">Fine ID</span>
                <span class="conf-value">#<?php echo $new_fine_data['id']; ?></span>
            </div>
            <div class="conf-item" style="background: white;">
                <span class="conf-label">Amount</span>
                <span class="conf-value" style="color: var(--danger-color);"><?php echo $currency . number_format($new_fine_data['amount'], 2); ?></span>
            </div>
            <div class="conf-item" style="grid-column: 1 / -1; background: white;">
                <span class="conf-label">Member</span>
                <span class="conf-value"><?php echo htmlspecialchars($new_fine_data['member_name']); ?> (<?php echo htmlspecialchars($new_fine_data['member_uid']); ?>)</span>
            </div>
            <div class="conf-item" style="grid-column: 1 / -1; background: white;">
                <span class="conf-label">Reason</span>
                <span class="conf-value"><?php echo htmlspecialchars($new_fine_data['type']); ?></span>
            </div>
        </div>
        
        <button onclick="document.getElementById('successModal').style.display='none'" class="modal-btn" style="background: var(--primary-text-color);">Close</button>
    </div>
</div>
<?php endif; ?>

<div id="cancelReasonModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="document.getElementById('cancelReasonModal').style.display='none'">&times;</span>
        <h3 style="color: var(--danger-color); margin-top:0;"><i class="fas fa-ban"></i> Cancel Fine</h3>
        <p style="color: var(--secondary-text-color); margin-bottom: 20px;">You are about to revert this fine. Please provide a reason for auditing purposes.</p>
        
        <div style="background: #f8fafc; padding: 10px; border-radius: 8px; margin-bottom: 15px; border: 1px solid #e2e8f0;">
            <p style="margin: 3px 0; font-size: 0.9rem;"><strong>Fine ID:</strong> <span id="cr_fine_id_display"></span></p>
            <p style="margin: 3px 0; font-size: 0.9rem;"><strong>Member:</strong> <span id="cr_member_display"></span></p>
            <p style="margin: 3px 0; font-size: 0.9rem;"><strong>Amount:</strong> <span id="cr_amount_display" style="color: var(--danger-color); font-weight: 700;"></span></p>
        </div>

        <div class="form-group">
            <label>Reason for Cancellation <span style="color:red">*</span></label>
            <textarea id="cancel_reason_input" class="modal-input" rows="3" placeholder="e.g. Waived by Principal, System Error, etc."></textarea>
        </div>
        
        <button type="button" onclick="proceedToConfirmCancel()" class="modal-btn" style="background: var(--accent-color);">Next: Confirm Details <i class="fas fa-arrow-right"></i></button>
    </div>
</div>

<div id="cancelConfirmModal" class="modal">
    <div class="modal-content">
        <h3 style="color: var(--danger-color); margin-top: 0;"><i class="fas fa-exclamation-triangle"></i> Confirm Revert</h3>
        <p>Are you sure you want to permanently cancel and archive this fine?</p>
        
        <div class="conf-grid">
            <div class="conf-item">
                <span class="conf-label">Fine ID</span>
                <span class="conf-value" id="cc_fine_id"></span>
            </div>
            <div class="conf-item">
                <span class="conf-label">Amount</span>
                <span class="conf-value" id="cc_amount" style="color: var(--danger-color);"></span>
            </div>
            <div class="conf-item" style="grid-column: 1 / -1;">
                <span class="conf-label">Member</span>
                <span class="conf-value" id="cc_member"></span>
            </div>
            <div class="conf-item" style="grid-column: 1 / -1;">
                <span class="conf-label">Reason</span>
                <span class="conf-value" id="cc_reason" style="font-style: italic;"></span>
            </div>
        </div>

        <form method="POST" id="cancelForm">
            <input type="hidden" name="action" value="archive_fine">
            <input type="hidden" name="fine_id" id="final_cancel_fine_id">
            <input type="hidden" name="cancel_reason" id="final_cancel_reason">
            
            <div style="display: flex; gap: 10px;">
                <button type="button" class="modal-btn" style="background: #e2e8f0; color: #334155;" onclick="document.getElementById('cancelConfirmModal').style.display='none'">Go Back</button>
                <button type="submit" class="modal-btn modal-btn-danger">Confirm Deletion</button>
            </div>
        </form>
    </div>
</div>

<div id="loadingOverlay">
    <div class="loader"></div>
    <h3 style="color: #111827; font-weight: 600;">Processing Transaction...</h3>
    <p style="color: #6b7280; font-size: 0.9rem;">Please do not refresh or close the page.</p>
</div>



<script>

    
    


/**
 * Toggles Transaction Ref ID field
 */
function toggleTransactionId(method) {
    const group = document.getElementById('transaction_id_group');
    const input = document.getElementById('transaction_id');
    if (method !== 'Cash' && method !== '' && method !== 'Other') {
        group.style.display = 'block';
        input.setAttribute('required', 'required');
    } else {
        group.style.display = 'none';
        input.removeAttribute('required');
    }
}

// --- CANCELLATION LOGIC ---
let pendingCancelData = {};

function openCancelModal(id, member, amount, type) {
    pendingCancelData = { id, member, amount, type };
    
    // Populate Step 1 Modal
    document.getElementById('cr_fine_id_display').textContent = '#' + id;
    document.getElementById('cr_member_display').textContent = member;
    document.getElementById('cr_amount_display').textContent = amount;
    document.getElementById('cancel_reason_input').value = ''; // Reset input
    
    document.getElementById('cancelReasonModal').style.display = 'flex';
}

function proceedToConfirmCancel() {
    const reason = document.getElementById('cancel_reason_input').value.trim();
    if (!reason) {
        alert("Please enter a reason for cancellation.");
        return;
    }
    pendingCancelData.reason = reason;

    // Populate Step 2 Modal
    document.getElementById('cc_fine_id').textContent = '#' + pendingCancelData.id;
    document.getElementById('cc_member').textContent = pendingCancelData.member;
    document.getElementById('cc_amount').textContent = pendingCancelData.amount;
    document.getElementById('cc_reason').textContent = pendingCancelData.reason;
    
    // Set Hidden Form Inputs
    document.getElementById('final_cancel_fine_id').value = pendingCancelData.id;
    document.getElementById('final_cancel_reason').value = pendingCancelData.reason;

    // Switch Modals
    document.getElementById('cancelReasonModal').style.display = 'none';
    document.getElementById('cancelConfirmModal').style.display = 'flex';
}

// --- COLLECTION LOGIC ---
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('collectFineModal');
    const collectButtons = document.querySelectorAll('.collect-fine-btn');

    collectButtons.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const member = this.getAttribute('data-member');
            const amount = this.getAttribute('data-amount');
            const currency = this.getAttribute('data-currency');

            document.getElementById('collect_fine_id').value = id;
            document.getElementById('modal_member_name').textContent = member;
            document.getElementById('modal_fine_amount').textContent = currency + amount;
            
            document.getElementById('payment_method').value = '';
            document.getElementById('transaction_id').value = '';
            toggleTransactionId('');

            modal.style.display = 'flex';
        });
    });

    window.onclick = function(event) {
        if (event.target == modal) modal.style.display = 'none';
        if (event.target == document.getElementById('cancelReasonModal')) document.getElementById('cancelReasonModal').style.display = 'none';
        if (event.target == document.getElementById('cancelConfirmModal')) document.getElementById('cancelConfirmModal').style.display = 'none';
        if (event.target == document.getElementById('successModal')) document.getElementById('successModal').style.display = 'none';
    }
});

// --- NEW FINE CREATION JS LOGIC ---

let debounceTimer;
let memberDetailsData = {}; 
const FINE_PER_DAY = <?php echo json_encode($fine_per_day); ?>;
const CURRENCY = <?php echo json_encode($currency); ?>;

function calculateLateFine(dueDateString) {
    const today = new Date();
    today.setHours(0, 0, 0, 0); 
    const due = new Date(dueDateString);
    due.setHours(0, 0, 0, 0);
    const diffTime = today - due;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    const overdueDays = Math.max(0, diffDays);
    const lateFine = parseFloat((overdueDays * FINE_PER_DAY).toFixed(2));
    return { days: overdueDays, lateFine: lateFine };
}

function fetchSuggestions(uidType, inputElement) {
    const query = inputElement.value;
    const suggestionBox = document.getElementById(inputElement.id + '_suggestions');
    if (query.length < 3) {
        suggestionBox.style.display = 'none';
        inputElement.style.borderRadius = '10px';
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
                            inputElement.style.borderRadius = '10px';
                            fetchMemberDetails(); 
                        };
                        suggestionBox.appendChild(itemElement);
                    });
                    suggestionBox.style.display = 'block';
                    inputElement.style.borderRadius = '10px 10px 0 0';
                } else {
                    suggestionBox.style.display = 'none';
                    inputElement.style.borderRadius = '10px';
                }
            })
            .catch(error => {
                console.error('Error fetching suggestions:', error);
                suggestionBox.style.display = 'none';
                inputElement.style.borderRadius = '10px';
            });
    }, 300); 
}

function fetchMemberDetails() {
    const uidInput = document.getElementById('member_uid_input');
    const memberIdInput = document.getElementById('member_id_input');
    const fallbackCircIdInput = document.getElementById('fallback_circulation_id'); 
    const detailsContainer = document.getElementById('member_details_container');
    const issuedBookSelect = document.getElementById('issued_book_select');

    const nameDisplay = document.getElementById('member_full_name_display');
    const emailDisplay = document.getElementById('member_email_display');
    const deptDisplay = document.getElementById('member_department_display');
    const statusDisplay = document.getElementById('member_status_display');
    const violationsDisplay = document.getElementById('member_violations_display');

    let memberUid = uidInput.value.trim();
    const match = memberUid.match(/\(([^)]+)\)$/);
    if (match) memberUid = match[1];

    if (memberUid.length > 0) {
        nameDisplay.textContent = 'Fetching...';
        detailsContainer.style.display = 'none';
        memberIdInput.value = '';
        fallbackCircIdInput.value = ''; 
        issuedBookSelect.innerHTML = '<option value="">-- No Books Currently Issued --</option>'; 

        fetch('fetch_member_details.php?member_uid=' + memberUid)
            .then(response => response.json())
            .then(data => {
                memberDetailsData = data; 
                if (data.success) {
                    nameDisplay.textContent = data.full_name;
                    emailDisplay.textContent = data.email;
                    deptDisplay.textContent = data.department;
                    violationsDisplay.textContent = data.violations;
                    statusDisplay.textContent = data.status;
                    statusDisplay.className = 'status-badge ' + (data.status === 'Active' ? 'status-paid' : 'status-pending');
                    memberIdInput.value = data.member_id;
                    fallbackCircIdInput.value = data.circulation_id || 0; 
                    
                    if (data.issued_books && data.issued_books.length > 0) {
                        issuedBookSelect.innerHTML = '<option value="">-- Select Issued Book --</option>';
                        data.issued_books.forEach((book, index) => {
                            const fine = calculateLateFine(book.due_date);
                            const option = document.createElement('option');
                            option.value = book.circulation_id;
                            option.textContent = `${book.book_title} (${book.book_uid}) - Due: ${book.due_date} ${fine.days > 0 ? '(OVERDUE: ' + fine.days + ' days)' : ''}`;
                            option.setAttribute('data-due-date', book.due_date);
                            option.setAttribute('data-late-fine', fine.lateFine);
                            option.setAttribute('data-overdue-days', fine.days);
                            issuedBookSelect.appendChild(option);
                        });
                        if (data.issued_books.length === 1) {
                             issuedBookSelect.selectedIndex = 1;
                             updateFineBreakdown();
                        }
                    } else {
                         issuedBookSelect.innerHTML = '<option value="">-- No Books Currently Issued --</option>';
                    }
                    
                    detailsContainer.style.display = 'grid'; 
                    toggleFineReasonFields();
                    const suggestionBox = document.getElementById('member_uid_input_suggestions');
                    if (suggestionBox) suggestionBox.style.display = 'none';
                    uidInput.style.borderRadius = '10px';
                } else {
                    nameDisplay.textContent = data.message;
                    memberIdInput.value = '';
                    toggleFineReasonFields();
                }
            })
            .catch(error => {
                console.error('Error fetching member details:', error);
                nameDisplay.textContent = 'Error during fetch.';
                memberIdInput.value = '';
                toggleFineReasonFields();
            });
    } else {
        nameDisplay.textContent = 'Enter UID above';
        detailsContainer.style.display = 'none';
        memberIdInput.value = '';
        toggleFineReasonFields();
    }
}

function toggleFineReasonFields() {
    const fineReason = document.getElementById('fine_reason').value;
    
    const bookGroup = document.getElementById('issued_book_group');
    const bookSelect = document.getElementById('issued_book_select');
    const memberIdInput = document.getElementById('member_id_input');
    const breakdownBox = document.getElementById('fine_breakdown');
    
    const otherGroup = document.getElementById('other_reason_group');
    const otherInput = document.getElementById('custom_fine_type');

    bookGroup.style.display = 'none';
    bookSelect.removeAttribute('required');
    otherGroup.style.display = 'none';
    otherInput.removeAttribute('required');
    breakdownBox.style.display = 'none';

    if (fineReason === 'Damaged Book' && memberIdInput.value) {
        if (bookSelect.options.length > 1) {
            bookGroup.style.display = 'block';
            bookSelect.setAttribute('required', 'required');
            breakdownBox.style.display = 'block';
            updateFineBreakdown();
        } else {
             bookGroup.style.display = 'block'; 
        }
        
    } else if (fineReason === 'Other') {
        otherGroup.style.display = 'block';
        otherInput.setAttribute('required', 'required');
    }
}

function updateFineBreakdown() {
    const fineReason = document.getElementById('fine_reason').value;
    const bookSelect = document.getElementById('issued_book_select');
    const baseFeeInput = document.getElementById('damaged_fine_amount');
    const totalFineInput = document.getElementById('total_fine_amount_input');
    
    let lateFine = 0.00;
    let overdueDays = 0;
    let dueDate = 'N/A';
    let baseFee = parseFloat(baseFeeInput.value) || 0.00;

    if (fineReason === 'Damaged Book' && bookSelect.value) {
        const selectedOption = bookSelect.options[bookSelect.selectedIndex];
        lateFine = parseFloat(selectedOption.getAttribute('data-late-fine')) || 0.00;
        overdueDays = parseInt(selectedOption.getAttribute('data-overdue-days')) || 0;
        dueDate = selectedOption.getAttribute('data-due-date');
    }

    const totalFine = (baseFee + lateFine).toFixed(2);
    
    document.getElementById('due_date_display').textContent = dueDate;
    document.getElementById('overdue_days_display').textContent = overdueDays + ' Days';
    document.getElementById('late_fine_display').textContent = CURRENCY + lateFine.toFixed(2);
    document.getElementById('base_fine_display').textContent = CURRENCY + baseFee.toFixed(2);
    document.getElementById('total_fine_display').textContent = CURRENCY + totalFine;
    
    totalFineInput.value = totalFine;
    
    const overdueRow = document.getElementById('overdue_days_display').closest('.fine-breakdown-row');
    if (overdueDays > 0) {
        overdueRow.style.color = 'var(--danger-color)';
    } else {
        overdueRow.style.color = 'var(--text-dark)';
    }
}

function toggleFineForm(button) {
    const content = document.getElementById('fineFormContent');
    if (content.style.display === 'none') {
        content.style.display = 'block';
        button.innerHTML = '<i class="fas fa-minus"></i> Close Form';
    } else {
        content.style.display = 'none';
        button.innerHTML = '<i class="fas fa-plus"></i> Open Form';
    }
}

document.addEventListener('click', function(e) {
    const inputContainer = document.querySelector('#fineFormContent .input-and-suggestions');
    const input = document.getElementById('member_uid_input');
    const box = document.getElementById('member_uid_input_suggestions');

    if (inputContainer && !inputContainer.contains(e.target)) {
        if (box && box.style.display !== 'none') {
            box.style.display = 'none';
            input.style.borderRadius = '10px';
        }
    }
});

window.fetchSuggestions = fetchSuggestions;
window.fetchMemberDetails = fetchMemberDetails;
window.toggleFineForm = toggleFineForm;
window.toggleFineReasonFields = toggleFineReasonFields;
window.updateFineBreakdown = updateFineBreakdown; 

document.addEventListener('DOMContentLoaded', toggleFineReasonFields);


// --- LOADING OVERLAY LOGIC (UPDATED) ---
document.addEventListener('DOMContentLoaded', function() {
    const loadingOverlay = document.getElementById('loadingOverlay');
    
    // Select ALL forms on the page (Create, Collect, Cancel, Search)
    const forms = document.querySelectorAll('form');

    forms.forEach(function(form) {
        form.addEventListener('submit', function(event) {
            // Only show loader if browser validation passes
            if (form.checkValidity()) {
                loadingOverlay.style.display = 'flex';
            }
        });
    });
});
</script>

<?php admin_footer(); close_db_connection($conn); ?>