<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

header('Content-Type: application/json');

$uid_type = $_GET['uid_type'] ?? '';
$query = trim($_GET['query'] ?? '');
$suggestions = [];

if (empty($query) && $uid_type !== 'member_details') {
    echo json_encode($suggestions);
    exit;
}

// --- 1. DETERMINE ADMIN RESTRICTIONS ---
$is_super = is_super_admin($conn);
$admin_id = $_SESSION['admin_id'];
$library_filter_sql = ""; 
$params = [];
$types = "";

// Prepare base search term
$search_term = "%" . $query . "%";
$params[] = $search_term;
$types .= "s";

if (!$is_super) {
    // Fetch Admin's Library
    $stmt_admin = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
    $stmt_admin->bind_param("i", $admin_id);
    $stmt_admin->execute();
    $my_lib_id = $stmt_admin->get_result()->fetch_assoc()['library_id'] ?? 0;

    if ($my_lib_id > 0) {
        // Filter queries by joining tbl_books and checking library_id
        $library_filter_sql = " AND tb.library_id = ? ";
        $params[] = $my_lib_id;
        $types .= "i";
    }
}

// --- 2. EXECUTE QUERIES ---

if ($uid_type === 'book') {
    // Filter by Library for Books
    $sql = "SELECT DISTINCT tbc.book_uid 
            FROM tbl_book_copies tbc 
            JOIN tbl_books tb ON tbc.book_id = tb.book_id
            WHERE tbc.book_uid NOT LIKE '%-BASE' 
            AND tbc.book_uid LIKE ? 
            AND tbc.status IN ('Available', 'Reserved')
            $library_filter_sql 
            LIMIT 10";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suggestions[] = $row['book_uid'];
    }

} elseif ($uid_type === 'issued_book') {
    // Filter by Library for Issued Books (Returns)
    $sql = "SELECT DISTINCT tbc.book_uid 
            FROM tbl_book_copies tbc 
            JOIN tbl_books tb ON tbc.book_id = tb.book_id
            WHERE tbc.book_uid NOT LIKE '%-BASE' 
            AND tbc.book_uid LIKE ? 
            AND tbc.status = 'Issued'
            $library_filter_sql 
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suggestions[] = $row['book_uid'];
    }

} elseif ($uid_type === 'reservation') {
    // Filter by Library for Reservations
    $sql = "SELECT tr.reservation_uid 
            FROM tbl_reservations tr
            JOIN tbl_books tb ON tr.book_id = tb.book_id
            WHERE tr.reservation_uid LIKE ? 
            AND tr.status = 'Accepted'
            $library_filter_sql
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $suggestions[] = $row['reservation_uid'];
    }



} elseif ($uid_type === 'member') {
    // Search by Name OR ID
    $sql = "SELECT member_uid, full_name FROM tbl_members 
            WHERE (member_uid LIKE ? OR full_name LIKE ?) 
            AND status = 'Active' LIMIT 10";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Format: "John Doe (UID123)"
        $suggestions[] = $row['full_name'] . " (" . $row['member_uid'] . ")";
    }
}
echo json_encode($suggestions);
close_db_connection($conn);
?>