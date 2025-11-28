<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

header('Content-Type: application/json');

$member_id = $_SESSION['member_id'];
$action = $_POST['action'] ?? '';
$book_id = (int)($_POST['book_id'] ?? 0);

// --- Helper to generate unique reservation ID ---
// Format: {INST}/{LIB}/{6-CHAR-ALPHANUMERIC}
function generate_unique_res_id($conn, $book_id) {
    // Fetch Institution Initials
    $inst = get_setting($conn, 'institution_initials');
    if (empty($inst)) $inst = 'INS'; 
    
    // Fetch Book's Library Initials
    $lib = 'LIB'; // Default
    // Query to get the library initials associated with the book being reserved
    $stmt = $conn->prepare("SELECT l.library_initials FROM tbl_books b JOIN tbl_libraries l ON b.library_id = l.library_id WHERE b.book_id = ?");
    $stmt->bind_param("i", $book_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $lib = $row['library_initials'];
    }
    
    // Construct the prefix part: e.g., "BWU/SCILIB/"
    $prefix = strtoupper($inst . '/' . $lib . '/');
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    
    do {
        $code = '';
        // Generate 6 random alphanumeric characters
        for ($i = 0; $i < 6; $i++) {
            $code .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        $reservation_uid = $prefix . $code;
        
        // Ensure uniqueness in DB to avoid duplicates
        $stmt = $conn->prepare("SELECT reservation_id FROM tbl_reservations WHERE reservation_uid = ?");
        $stmt->bind_param("s", $reservation_uid);
        $stmt->execute();
        $result = $stmt->get_result();
    } while ($result->num_rows > 0);

    return $reservation_uid;
}

if ($action === 'reserve') {
    if ($book_id === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Book ID.']);
        exit;
    }
    
    // 1. Check if already reserved by this member
    $stmt_check = $conn->prepare("SELECT reservation_id FROM tbl_reservations WHERE member_id = ? AND book_id = ? AND status IN ('Pending', 'Accepted')");
    $stmt_check->bind_param("ii", $member_id, $book_id);
    $stmt_check->execute();
    if ($stmt_check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have an active reservation for this book.']);
        exit;
    }

    // --- NEW LOGIC: FETCH BOOK BASE UID ---
    $stmt_uid = $conn->prepare("SELECT book_uid FROM tbl_book_copies WHERE book_id = ? AND book_uid LIKE '%-BASE' LIMIT 1");
    $stmt_uid->bind_param("i", $book_id);
    $stmt_uid->execute();
    $base_uid_raw = $stmt_uid->get_result()->fetch_assoc()['book_uid'] ?? '';
    
    if (empty($base_uid_raw)) {
        echo json_encode(['success' => false, 'message' => 'Could not find the base ID for this book.']);
        exit;
    }
    
    // Remove the '-BASE' suffix to get the required base UID (e.g., 'BWU/CLIB/XXXXXX')
    $book_base_uid = substr($base_uid_raw, 0, -5); 

    // 2. Generate formatted ID (Updated to use book's library)
    $reservation_uid = generate_unique_res_id($conn, $book_id);

    // 3. Insert Reservation - UPDATED INSERT STATEMENT
    $stmt = $conn->prepare("INSERT INTO tbl_reservations (reservation_uid, member_id, book_id, book_base_uid, status) VALUES (?, ?, ?, ?, 'Pending')");
    $stmt->bind_param("siis", $reservation_uid, $member_id, $book_id, $book_base_uid);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Reserved successfully.', 
            'reservation_uid' => $reservation_uid
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }

} elseif ($action === 'cancel') {
    $res_id = (int)($_POST['reservation_id'] ?? 0);
    
    if ($res_id === 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Reservation ID.']);
        exit;
    }

    // Update status to Cancelled AND record that 'Member' did it
    // Only allow cancelling if status is Pending
    $stmt = $conn->prepare("UPDATE tbl_reservations SET status = 'Cancelled', cancelled_by = 'Member' WHERE reservation_id = ? AND member_id = ? AND status = 'Pending'");
    $stmt->bind_param("ii", $res_id, $member_id);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Reservation cancelled.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not cancel reservation. It might already be processed or does not exist.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

close_db_connection($conn);
?>