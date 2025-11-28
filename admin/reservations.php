<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';

// --- Handle Reservation Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reservation_id = (int)($_POST['reservation_id'] ?? 0);

    if ($reservation_id > 0) {
        if ($action === 'accept') {
            $stmt = $conn->prepare("UPDATE tbl_reservations SET status = 'Accepted' WHERE reservation_id = ? AND status = 'Pending'");
            $stmt->bind_param("i", $reservation_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Reservation #{$reservation_id} accepted successfully.";
                
                // --- EMAIL NOTIFICATION LOGIC START ---
                // Fetch details for the email
                $stmt_details = $conn->prepare("
                    SELECT tm.email, tm.full_name, tr.reservation_uid, tr.reservation_date,
                           tb.title, tb.author, tb.isbn, tb.category
                    FROM tbl_reservations tr
                    JOIN tbl_members tm ON tr.member_id = tm.member_id
                    JOIN tbl_books tb ON tr.book_id = tb.book_id
                    WHERE tr.reservation_id = ?
                ");
                $stmt_details->bind_param("i", $reservation_id);
                $stmt_details->execute();
                $res_data = $stmt_details->get_result()->fetch_assoc();
                $stmt_details->close();

                if ($res_data && !empty($res_data['email'])) {
                    // Generate QR Code (Base64 encoded for email embedding)
                    $qr_data = urlencode($res_data['reservation_uid']);
                    $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data={$qr_data}";
                    $qr_image_content = @file_get_contents($qr_api_url);
                    $qr_img_src = "";
                    
                    if ($qr_image_content) {
                        $qr_base64 = base64_encode($qr_image_content);
                        $qr_img_src = "data:image/png;base64,{$qr_base64}";
                    } else {
                        $qr_img_src = $qr_api_url; // Fallback to URL
                    }

                    $subject = "Reservation Accepted: " . $res_data['title'];
                    $body = "
                        <div style='font-family: Arial, sans-serif; color: #333;'>
                            <h2 style='color: #10b981;'>Reservation Confirmed</h2>
                            <p>Dear <strong>" . htmlspecialchars($res_data['full_name']) . "</strong>,</p>
                            <p>Your reservation request has been accepted. The book is now reserved for you.</p>
                            
                            <table style='width: 100%; border-collapse: collapse; margin-top: 15px; border: 1px solid #eee;'>
                                <tr style='background: #f9fafb;'><td style='padding: 8px; font-weight: bold;'>Reservation ID</td><td style='padding: 8px;'>" . htmlspecialchars($res_data['reservation_uid']) . "</td></tr>
                                <tr><td style='padding: 8px; font-weight: bold;'>Book Title</td><td style='padding: 8px;'>" . htmlspecialchars($res_data['title']) . "</td></tr>
                                <tr style='background: #f9fafb;'><td style='padding: 8px; font-weight: bold;'>Author</td><td style='padding: 8px;'>" . htmlspecialchars($res_data['author']) . "</td></tr>
                                <tr><td style='padding: 8px; font-weight: bold;'>ISBN</td><td style='padding: 8px;'>" . htmlspecialchars($res_data['isbn'] ?? 'N/A') . "</td></tr>
                                <tr style='background: #f9fafb;'><td style='padding: 8px; font-weight: bold;'>Date</td><td style='padding: 8px;'>" . date('d M Y', strtotime($res_data['reservation_date'])) . "</td></tr>
                            </table>
                            
                            <div style='text-align: center; margin: 20px 0; padding: 15px; background: #f3f4f6; border-radius: 8px;'>
                                <p style='margin: 0 0 10px 0; font-weight: bold; color: #555;'>Reservation QR Code</p>
                                <p style='margin: 5px 0 0 0; font-size: 0.8em; color: #777;'>Show the Reservation QR Code at the library desk, From the reservation page of your dashboard</p>
                            </div>
                            
                            <p>Please collect your book within the designated time frame.</p>
                        </div>
                    ";
                    
                    // Send Email
                    send_system_email($res_data['email'], $res_data['full_name'], $subject, $body);
                }
                // --- EMAIL NOTIFICATION LOGIC END ---

            } else {
                $error = "Error accepting reservation. It may not be in 'Pending' status.";
            }
        } elseif ($action === 'reject') {
            $cancel_by = "Admin: " . ($_SESSION['admin_full_name'] ?? 'Unknown');
            $stmt = $conn->prepare("UPDATE tbl_reservations SET status = 'Rejected', cancelled_by = ? WHERE reservation_id = ? AND status = 'Pending'");
            $stmt->bind_param("si", $cancel_by, $reservation_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Reservation #{$reservation_id} rejected.";
            } else {
                $error = "Error rejecting reservation.";
            }
        } elseif ($action === 'cancel_accepted') {
            $cancel_by = "Admin: " . ($_SESSION['admin_full_name'] ?? 'Unknown');
            $stmt = $conn->prepare("UPDATE tbl_reservations SET status = 'Cancelled', cancelled_by = ? WHERE reservation_id = ? AND status = 'Accepted'");
            $stmt->bind_param("si", $cancel_by, $reservation_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $message = "Reservation #{$reservation_id} has been cancelled.";
            } else {
                $error = "Error cancelling reservation.";
            }
        } elseif ($action === 'fulfill') {
             // 1. Get details
             $stmt_res = $conn->prepare("SELECT book_id, member_id, reservation_uid FROM tbl_reservations WHERE reservation_id = ? AND status = 'Accepted'");
             $stmt_res->bind_param("i", $reservation_id);
             $stmt_res->execute();
             $res_data = $stmt_res->get_result()->fetch_assoc();

             if ($res_data) {
                 $book_id = $res_data['book_id'];
                 $member_id = $res_data['member_id'];
                 
                 // 2. Check availability
                 $stmt_copy = $conn->prepare("SELECT copy_id, book_uid FROM tbl_book_copies WHERE book_id = ? AND status = 'Available' LIMIT 1");
                 $stmt_copy->bind_param("i", $book_id);
                 $stmt_copy->execute();
                 $copy_data = $stmt_copy->get_result()->fetch_assoc();

                 if ($copy_data) {
                     $copy_id = $copy_data['copy_id'];
                     $book_uid = $copy_data['book_uid'];
                     $admin_id = $_SESSION['admin_id'];
                     $max_days = get_setting($conn, 'max_borrow_days') ?? 14;
                     $due_date = date('Y-m-d', strtotime("+$max_days days"));

                     $conn->begin_transaction();
                     try {
                         // Create Circulation Record
                         $stmt_issue = $conn->prepare("INSERT INTO tbl_circulation (copy_id, member_id, issue_date, due_date, status, issued_by_admin_id) VALUES (?, ?, NOW(), ?, 'Issued', ?)");
                         $stmt_issue->bind_param("iisi", $copy_id, $member_id, $due_date, $admin_id);
                         $stmt_issue->execute();

                         // Update Copy Status
                         $stmt_upd_copy = $conn->prepare("UPDATE tbl_book_copies SET status = 'Issued' WHERE copy_id = ?");
                         $stmt_upd_copy->bind_param("i", $copy_id);
                         $stmt_upd_copy->execute();

                         // Update Book Counts
                         $stmt_upd_book = $conn->prepare("UPDATE tbl_books SET available_quantity = available_quantity - 1 WHERE book_id = ?");
                         $stmt_upd_book->bind_param("i", $book_id);
                         $stmt_upd_book->execute();

                         // Update Reservation Status
                         $stmt_upd_res = $conn->prepare("UPDATE tbl_reservations SET status = 'Fulfilled' WHERE reservation_id = ?");
                         $stmt_upd_res->bind_param("i", $reservation_id);
                         $stmt_upd_res->execute();

                         $conn->commit();
                         $message = "Reservation fulfilled! Book {$book_uid} issued.";
                     } catch (Exception $e) {
                         $conn->rollback();
                         $error = "Transaction failed: " . $e->getMessage();
                     }
                 } else {
                     $error = "Failed: No copies currently available to fulfill this request.";
                 }
             } else {
                 $error = "Invalid reservation status.";
             }
        }
    }
}

// --- Fetch Reservations for View ---
$status_filter = $_GET['status'] ?? 'Pending';
$search_query = trim($_GET['search'] ?? '');

// 1. Determine Library Scope for Filtering
$is_super = is_super_admin($conn);
$admin_id = $_SESSION['admin_id'];
$library_filter_sql = "";
$lib_params = [];
$lib_types = "";

if (!$is_super) {
    // Fetch Admin's Assigned Library
    $stmt_lib = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
    $stmt_lib->bind_param("i", $admin_id);
    $stmt_lib->execute();
    $my_lib_id = $stmt_lib->get_result()->fetch_assoc()['library_id'] ?? 0;

    // Only apply filter if a library is actually assigned
    if ($my_lib_id > 0) {
        $library_filter_sql = " AND tb.library_id = ? ";
        $lib_params[] = $my_lib_id;
        $lib_types = "i";
    }
}

$sql = "
    SELECT 
        tr.*, 
        tb.title AS book_title, 
        tb.author, 
        tb.cover_image, /* Fetch Cover Image */
        tb.library_id, 
        tm.full_name AS member_name,
        tm.member_uid
    FROM 
        tbl_reservations tr
    JOIN 
        tbl_books tb ON tr.book_id = tb.book_id
    JOIN 
        tbl_members tm ON tr.member_id = tm.member_id
    WHERE 
        tr.status = ?
        $library_filter_sql
";

$params = [$status_filter];
$types = 's';

// Merge Library Params if they exist
if (!empty($lib_params)) {
    $params = array_merge($params, $lib_params);
    $types .= $lib_types;
}

if (!empty($search_query)) {
    $sql .= " AND (tb.title LIKE ? OR tm.full_name LIKE ? OR tr.reservation_uid LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
    $types .= 'sss';
}

$sql .= " ORDER BY tr.reservation_date DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reservations_result = $stmt->get_result();

admin_header('Reservation Management');
?>

<style>
    /* Glass Components */
    .glass-card {
        background: rgba(255, 255, 255, 0.65);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.6);
        box-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.15);
        border-radius: 20px;
        padding: 30px;
        animation: fadeIn 0.5s ease-out;
    }
    
    /* General Text Color: Deep Black (for high contrast) */
    body, h2, h3, th, td, .modal h3, .modal p {
        color: #111827; /* Deep Black */
    }


    /* Filter Tabs */
    .filter-container {
        display: flex;
        gap: 10px;
        margin-bottom: 25px;
        overflow-x: auto;
        padding-bottom: 5px;
    }

    .filter-pill {
        padding: 10px 20px;
        border-radius: 30px;
        text-decoration: none;
        color: #111827; /* Deep Black */
        font-weight: 600;
        font-size: 0.9rem;
        background: rgba(255, 255, 255, 0.4);
        border: 1px solid rgba(255, 255, 255, 0.5);
        transition: all 0.3s ease;
        white-space: nowrap;
    }

    .filter-pill:hover {
        background: rgba(255, 255, 255, 0.8);
        transform: translateY(-2px);
    }

    .filter-pill.active {
        background: #6366f1;
        color: white;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4);
        border-color: #6366f1;
    }

    /* Search Bar */
    .search-wrapper {
        position: relative;
        flex: 1;
        max-width: 400px;
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
    }

    .form-control {
        width: 100%;
        padding: 12px 12px 12px 40px;
        border: 1px solid rgba(0, 0, 0, 0.1);
        background: rgba(255, 255, 255, 0.9); /* High visibility input */
        border-radius: 12px;
        font-size: 0.95rem;
        color: #111827; /* Deep Black */
        transition: all 0.3s;
    }

    .form-control:focus {
        background: #fff;
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        outline: none;
    }

    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b; /* Darker gray for icon */
    }

    /* Table Styles */
    .table-container { overflow-x: auto; border-radius: 12px; }
    .glass-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
    }
    
    .glass-table th {
        background: rgba(99, 102, 241, 0.1);
        color: #111827; /* Deep Black */
        font-weight: 700;
        padding: 15px;
        text-align: left;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .glass-table td {
        padding: 15px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        color: #111827; /* Deep Black */
        font-size: 0.95rem;
        vertical-align: middle;
    }
    
    .glass-table tr:hover { background: rgba(255,255,255,0.8); }

    /* Badges */
    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
    }

    .status-Pending { background: #fef9c3; color: #a16207; }
    .status-Accepted { background: #dcfce7; color: #166534; }
    .status-Rejected { background: #fee2e2; color: #991b1b; }
    .status-Fulfilled { background: #f1f5f9; color: #475569; }
    .status-Cancelled { background: #f3f4f6; color: #6b7280; text-decoration: line-through; }

    /* Action Buttons */
    .action-btn {
        width: 35px;
        height: 35px;
        border-radius: 8px;
        border: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: 0.2s;
        margin-right: 5px;
        color: white;
    }
    
    .btn-accept { background: #10b981; box-shadow: 0 2px 5px rgba(16, 185, 129, 0.3); }
    .btn-reject { background: #ef4444; box-shadow: 0 2px 5px rgba(239, 68, 68, 0.3); }
    .btn-fulfill { background: #3b82f6; box-shadow: 0 2px 5px rgba(59, 130, 246, 0.3); }
    .btn-cancel { background: #f59e0b; box-shadow: 0 2px 5px rgba(245, 158, 11, 0.3); }

    .action-btn:hover { transform: translateY(-2px); opacity: 0.9; }

    /* Alerts */
    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

    /* --- Universal Modal --- */
    .modal {
        display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%;
        background-color: rgba(15, 23, 42, 0.7); backdrop-filter: blur(8px);
        justify-content: center; align-items: center;
    }
    .modal-content {
        background: #fff;
        padding: 35px;
        border-radius: 24px;
        width: 90%;
        max-width: 450px;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: modalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
    }
    @keyframes modalPop { 
        from { transform: scale(0.8); opacity: 0; } 
        to { transform: scale(1); opacity: 1; } 
    }

    .modal-icon {
        font-size: 3.5rem; margin-bottom: 15px; display: block;
    }
    
    .modal h3 { margin: 0 0 10px 0; color: #111827; font-size: 1.5rem; }
    .modal p { color: #374151; margin-bottom: 25px; line-height: 1.5; }

    .modal-actions { display: flex; gap: 10px; justify-content: center; }
    
    .modal-btn {
        padding: 12px 24px; border-radius: 12px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s;
        font-size: 1rem;
    }
    .btn-close { background: #f1f5f9; color: #475569; }
    .btn-close:hover { background: #e2e8f0; }
    
    /* Dynamic colors for confirm button */
    .confirm-accept { background: #10b981; color: white; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); }
    .confirm-reject { background: #ef4444; color: white; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3); }
    .confirm-fulfill { background: #3b82f6; color: white; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
    .confirm-cancel { background: #f59e0b; color: white; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3); }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    
    /* Loading Overlay Styles */
    #loadingOverlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.85);
        backdrop-filter: blur(5px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }
    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #e2e8f0;
        border-top: 5px solid #6366f1;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    .loading-text {
        font-family: 'Plus Jakarta Sans', sans-serif;
        color: #1e293b;
        font-weight: 600;
        font-size: 1.1rem;
    }
</style>

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">Processing Request & Sending Email...</div>
</div>

<div class="glass-card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
        <h2 style="margin: 0; color: #111827; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-calendar-check" style="color: #6366f1;"></i> Reservation Management
        </h2>
    </div>

    <?php if ($message): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> <?php endif; ?>
    <?php if ($error): ?> <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> <?php endif; ?>

    <div class="filter-container">
        <a href="reservations.php?status=Pending" class="filter-pill <?php echo $status_filter === 'Pending' ? 'active' : ''; ?>">Pending</a>
        <a href="reservations.php?status=Accepted" class="filter-pill <?php echo $status_filter === 'Accepted' ? 'active' : ''; ?>">Accepted</a>
        <a href="reservations.php?status=Fulfilled" class="filter-pill <?php echo $status_filter === 'Fulfilled' ? 'active' : ''; ?>">Fulfilled</a>
        <a href="reservations.php?status=Rejected" class="filter-pill <?php echo $status_filter === 'Rejected' ? 'active' : ''; ?>">Rejected</a>
        <a href="reservations.php?status=Cancelled" class="filter-pill <?php echo $status_filter === 'Cancelled' ? 'active' : ''; ?>">Cancelled</a>
    </div>

    <form method="GET" class="search-wrapper">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
        <i class="fas fa-search search-icon"></i>
        <input type="search" name="search" class="form-control" placeholder="Search by ID, Book, or Member..." value="<?php echo htmlspecialchars($search_query); ?>">
        <button type="submit" class="action-btn" style="background: #334155; width: 45px; height: 42px;"><i class="fas fa-arrow-right"></i></button>
        <?php if (!empty($search_query)): ?> 
            <a href="reservations.php?status=<?php echo $status_filter; ?>" class="action-btn" style="background: #ef4444; width: 45px; height: 42px; text-decoration: none;"><i class="fas fa-times"></i></a> 
        <?php endif; ?>
    </form>

    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Res. ID</th>
                    <th>Book Details</th>
                    <th>Member Details</th>
                    <th>Date Requested</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($reservations_result->num_rows > 0): ?>
                    <?php while ($res = $reservations_result->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong style="color: #6366f1; font-family: monospace; font-size: 1rem;">
                                    <?php echo htmlspecialchars($res['reservation_uid']); ?>
                                </strong>
                            </td>
                            <td>
                                <div>
                                    <div style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($res['book_title']); ?></div>
                                    <div style="font-size: 0.85rem; color: #374151;"><?php echo htmlspecialchars($res['author']); ?></div>
                                    <div style="font-size: 0.75rem; color: #4f46e5; font-family: monospace; margin-top: 2px;">
                                        <?php echo htmlspecialchars($res['book_base_uid'] ?? 'N/A'); ?>
                                    </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight: 600; color: #111827;"><?php echo htmlspecialchars($res['member_name']); ?></div>
                                <div style="font-size: 0.8rem; font-family: 'Courier New', monospace; color: #111827; background: rgba(203, 213, 225, 0.6); display: inline-block; padding: 2px 6px; border-radius: 4px; margin-top: 2px; border: 1px solid rgba(0,0,0,0.1); font-weight:600;">
                                    <?php echo htmlspecialchars($res['member_uid']); ?>
                                </div>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($res['reservation_date'])); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $res['status']; ?>"><?php echo $res['status']; ?></span>
                                <?php if(($res['status'] === 'Cancelled' || $res['status'] === 'Rejected') && !empty($res['cancelled_by'])): ?>
                                    <div style="font-size: 0.75rem; color: #ef4444; margin-top: 4px; font-weight: 500;">
                                        By: <?php echo htmlspecialchars($res['cancelled_by']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex;">
                                <?php if ($res['status'] === 'Pending'): ?>
                                        
                                    <button type="button" class="action-btn btn-accept" title="Accept" 
                                        onclick='triggerAction("accept", <?php echo json_encode($res); ?>)'>
                                        <i class="fas fa-check"></i>
                                    </button>
                                        
                                    <button type="button" class="action-btn btn-reject" title="Reject"
                                        onclick='triggerAction("reject", <?php echo json_encode($res); ?>)'>
                                        <i class="fas fa-times"></i>
                                    </button>

                                <?php elseif ($res['status'] === 'Accepted'): ?>
                                    <button type="button" class="action-btn btn-cancel" title="Cancel Reservation"
                                        onclick='triggerAction("cancel_accepted", <?php echo json_encode($res); ?>)'>
                                        <i class="fas fa-ban"></i>
                                    </button>

                                <?php else: ?>
                                    <span style="color:#cbd5e1; font-size: 0.9rem;">&mdash;</span>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center; padding:30px; color: #94a3b8;">No <?php echo strtolower($status_filter); ?> reservations found for your library.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="universalModal" class="modal">
    <div class="modal-content">
        <i id="modalIcon" class="fas fa-question-circle modal-icon"></i>
        <h3 id="modalTitle">Confirmation</h3>
        <div id="modalDetails" style="text-align: left; margin-bottom: 25px; padding: 15px; background: #f8f8f8; border-radius: 12px; border: 1px solid rgba(0,0,0,0.05);">
            <h4 style="margin: 0 0 10px 0; color: #6366f1; font-size: 0.9rem; text-transform: uppercase; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 5px;">Reservation Context</h4>
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 8px; font-size: 0.9rem;">
                <span style="font-weight: 700; color: #111827;">Book Title:</span> <strong id="modalBookTitle"></strong>
                <span style="font-weight: 700; color: #111827;">Book ID (Base):</span> <strong id="modalBookID"></strong> 
                <span style="font-weight: 700; color: #111827;">Member Name:</span> <strong id="modalMemberName"></strong>
                <span style="font-weight: 700; color: #111827;">Member ID:</span> <strong id="modalMemberUID"></strong>
                <span style="font-weight: 700; color: #111827;">Request Date:</span> <strong id="modalReqDate"></strong>
            </div>
        </div>
        <p id="modalDesc">Are you sure?</p>
        
        <form method="POST" id="actionForm">
            <input type="hidden" name="action" id="modalAction">
            <input type="hidden" name="reservation_id" id="modalResId">
            
            <div class="modal-actions">
                <button type="submit" id="modalConfirmBtn" class="modal-btn">Yes, Proceed</button>
                <button type="button" class="modal-btn btn-close" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    // Updated signature to accept full data object
    function triggerAction(action, data) {
        const modal = document.getElementById('universalModal');
        const icon = document.getElementById('modalIcon');
        const title = document.getElementById('modalTitle');
        const desc = document.getElementById('modalDesc');
        const btn = document.getElementById('modalConfirmBtn');
        const actionInput = document.getElementById('modalAction');
        const idInput = document.getElementById('modalResId');

        // Elements for details
        const bookTitle = document.getElementById('modalBookTitle');
        const bookID = document.getElementById('modalBookID');
        const memberName = document.getElementById('modalMemberName');
        const memberUID = document.getElementById('modalMemberUID');
        const reqDate = document.getElementById('modalReqDate');


        // Reset Classes (Remove old button colors)
        btn.className = 'modal-btn'; 
        icon.style.color = ''; 

        // 1. Populate Details
        bookTitle.innerText = data.book_title;
        bookID.innerText = data.book_base_uid; 
        memberName.innerText = data.member_name;
        memberUID.innerText = data.member_uid;
        // Format date for display
        reqDate.innerText = new Date(data.reservation_date).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });


        // 2. Set Hidden Inputs
        actionInput.value = action;
        idInput.value = data.reservation_id;


        // 3. Customize Content based on Action
        if (action === 'accept') {
            icon.className = 'fas fa-check-circle modal-icon';
            icon.style.color = '#10b981';
            title.innerText = 'Accept Request';
            desc.innerText = 'Are you sure you want to accept this reservation? This will trigger an email notification to the user.';
            btn.classList.add('confirm-accept');
            btn.innerText = 'Yes, Accept & Notify';
        } 
        else if (action === 'reject') {
            icon.className = 'fas fa-times-circle modal-icon';
            icon.style.color = '#ef4444';
            title.innerText = 'Reject Request';
            desc.innerText = 'Are you sure you want to reject this reservation? This action cannot be undone.';
            btn.classList.add('confirm-reject');
            btn.innerText = 'Yes, Reject';
        }
        else if (action === 'cancel_accepted') {
            icon.className = 'fas fa-ban modal-icon';
            icon.style.color = '#f59e0b';
            title.innerText = 'Cancel Reservation';
            desc.innerText = 'Are you sure you want to cancel this already accepted reservation? This removes the reservation completely.';
            btn.classList.add('confirm-cancel');
            btn.innerText = 'Yes, Cancel';
        }

        modal.style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('universalModal').style.display = 'none';
    }

    // Close modal on outside click
    window.onclick = function(event) {
        const modal = document.getElementById('universalModal');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }

    // Loading Overlay Logic
    document.getElementById('actionForm').addEventListener('submit', function() {
        document.getElementById('loadingOverlay').style.display = 'flex';
        document.getElementById('universalModal').style.display = 'none'; // Hide the confirmation modal
    });

</script>

<?php admin_footer(); close_db_connection($conn); ?>