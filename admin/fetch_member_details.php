<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

header('Content-Type: application/json');

$member_uid = trim($_GET['member_uid'] ?? '');
$details = [];

if (!empty($member_uid)) {
    // 1. Fetch member details
    $sql_member = "
        SELECT 
            tm.member_id, 
            tm.full_name, 
            tm.member_uid,
            tm.email,
            tm.department,
            tm.status,
            tm.screenshot_violations
        FROM 
            tbl_members tm
        WHERE 
            tm.member_uid = ? AND tm.status = 'Active'
    ";
    $stmt_member = $conn->prepare($sql_member);
    $stmt_member->bind_param("s", $member_uid);
    $stmt_member->execute();
    $result_member = $stmt_member->get_result();

    if ($row = $result_member->fetch_assoc()) {
        
        // 2. Fetch all currently issued books for this member (for Damaged Book fines)
        $sql_issued = "
            SELECT 
                tc.circulation_id, 
                tc.due_date, /* NEW: Retrieve Due Date */
                tb.title, 
                tbc.book_uid 
            FROM 
                tbl_circulation tc
            JOIN
                tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
            JOIN
                tbl_books tb ON tbc.book_id = tb.book_id
            WHERE 
                tc.member_id = ? AND tc.status = 'Issued'
            ORDER BY 
                tc.issue_date DESC
        ";
        $stmt_issued = $conn->prepare($sql_issued);
        $stmt_issued->bind_param("i", $row['member_id']);
        $stmt_issued->execute();
        $result_issued = $stmt_issued->get_result();
        
        $issued_books = [];
        while ($issued_row = $result_issued->fetch_assoc()) {
            $issued_books[] = [
                'circulation_id' => $issued_row['circulation_id'],
                'book_title' => htmlspecialchars($issued_row['title']),
                'book_uid' => htmlspecialchars($issued_row['book_uid']),
                'due_date' => $issued_row['due_date'] /* NEW: Add due date */
            ];
        }
        
        // 3. Fallback circulation_id for generic manual fines (from existing logic, fetching any returned one)
        $sql_fallback_circ = "
            SELECT 
                tc.circulation_id 
            FROM 
                tbl_circulation tc 
            WHERE 
                tc.member_id = ? AND tc.status = 'Returned' 
            ORDER BY 
                tc.issue_date DESC 
            LIMIT 1
        ";
        $stmt_fallback_circ = $conn->prepare($sql_fallback_circ);
        $stmt_fallback_circ->bind_param("i", $row['member_id']);
        $stmt_fallback_circ->execute();
        $fallback_circ_id = $stmt_fallback_circ->get_result()->fetch_assoc()['circulation_id'] ?? 0;


        $details = [
            'success' => true,
            'member_id' => $row['member_id'],
            'full_name' => htmlspecialchars($row['full_name']),
            'member_uid' => htmlspecialchars($row['member_uid']),
            'email' => htmlspecialchars($row['email'] ?? 'N/A'),
            'department' => htmlspecialchars($row['department']),
            'status' => htmlspecialchars($row['status']),
            'violations' => (int)$row['screenshot_violations'],
            'circulation_id' => $fallback_circ_id, // Fallback for generic fines
            'issued_books' => $issued_books // List for 'Damaged Book' fine type
        ];
    } else {
        $details = ['success' => false, 'message' => 'Member not found or inactive.'];
    }
} else {
    $details = ['success' => false, 'message' => 'Invalid Member UID.'];
}

echo json_encode($details);
close_db_connection($conn);
?>