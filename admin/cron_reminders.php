<?php
/**
 * Daily Cron Job: Send Reminder Emails for Book Returns
 * Run this script daily via server cron.
 * Features: 
 * 1. Upcoming Reminders (based on configured days)
 * 2. Due Today Reminder
 * 3. Overdue Reminders (Daily)
 */

// Adjust paths as this file is inside /admin/
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

global $conn;

echo "Starting Daily Reminder Process...\n";

$sent_count = 0;
$today = date('Y-m-d');

// 1. UPCOMING REMINDERS (Configured Days Before Due)
$days_setting = get_setting($conn, 'reminder_days'); // e.g., "1,3"
if (!empty($days_setting)) {
    $days_array = explode(',', $days_setting);
    foreach ($days_array as $day_offset) {
        $day_offset = (int)trim($day_offset);
        if ($day_offset <= 0) continue;

        $target_date = date('Y-m-d', strtotime("+$day_offset days"));
        
        $sql = "SELECT tc.due_date, tm.full_name, tm.email, tb.title, tbc.book_uid, l.library_name 
                FROM tbl_circulation tc 
                JOIN tbl_members tm ON tc.member_id = tm.member_id
                JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                JOIN tbl_books tb ON tbc.book_id = tb.book_id
                LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                WHERE tc.status = 'Issued' AND DATE(tc.due_date) = ? AND tm.email IS NOT NULL AND tm.email != ''";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $target_date);
        $stmt->execute();
        $res = $stmt->get_result();
        
        while($row = $res->fetch_assoc()) {
            $subject = "Reminder: Book Due in $day_offset Day(s)";
            $body = "Dear " . $row['full_name'] . ",<br><br>This is a reminder that the book '<strong>" . $row['title'] . "</strong>' (" . $row['book_uid'] . ") is due for return on <strong>" . date('d M Y', strtotime($row['due_date'])) . "</strong>.<br><br>Please return it to " . ($row['library_name'] ?? 'the library') . " to avoid fines.";
            
            // Inject specific library name into email function if supported, otherwise default handled inside function
            if(send_system_email($row['email'], $row['full_name'], $subject, $body, $row['library_name'])) {
                echo "Sent upcoming reminder to " . $row['email'] . "\n";
                $sent_count++;
            }
        }
        $stmt->close();
    }
}

// 2. DUE TODAY REMINDERS
$sql_today = "SELECT tc.due_date, tm.full_name, tm.email, tb.title, tbc.book_uid, l.library_name 
              FROM tbl_circulation tc 
              JOIN tbl_members tm ON tc.member_id = tm.member_id
              JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
              JOIN tbl_books tb ON tbc.book_id = tb.book_id
              LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
              WHERE tc.status = 'Issued' AND DATE(tc.due_date) = ? AND tm.email IS NOT NULL AND tm.email != ''";

$stmt = $conn->prepare($sql_today);
$stmt->bind_param("s", $today);
$stmt->execute();
$res = $stmt->get_result();

while($row = $res->fetch_assoc()) {
    $subject = "Action Required: Book Due Today";
    $body = "Dear " . $row['full_name'] . ",<br><br>The book '<strong>" . $row['title'] . "</strong>' (" . $row['book_uid'] . ") is due <strong>TODAY</strong> (" . date('d M Y', strtotime($row['due_date'])) . ").<br><br>Please return it to " . ($row['library_name'] ?? 'the library') . " immediately to avoid late fines.";
    
    if(send_system_email($row['email'], $row['full_name'], $subject, $body, $row['library_name'])) {
        echo "Sent due today reminder to " . $row['email'] . "\n";
        $sent_count++;
    }
}
$stmt->close();

// 3. OVERDUE REMINDERS (Daily)
// Checks for any book that is 'Issued' and due date is BEFORE today
$sql_overdue = "SELECT tc.due_date, tm.full_name, tm.email, tb.title, tbc.book_uid, l.library_name 
                FROM tbl_circulation tc 
                JOIN tbl_members tm ON tc.member_id = tm.member_id
                JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                JOIN tbl_books tb ON tbc.book_id = tb.book_id
                LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                WHERE tc.status = 'Issued' AND DATE(tc.due_date) < ? AND tm.email IS NOT NULL AND tm.email != ''";

$stmt = $conn->prepare($sql_overdue);
$stmt->bind_param("s", $today);
$stmt->execute();
$res = $stmt->get_result();

while($row = $res->fetch_assoc()) {
    $due_date_ts = strtotime($row['due_date']);
    $overdue_days = floor((time() - $due_date_ts) / (60 * 60 * 24));
    
    $subject = "OVERDUE NOTICE: Immediate Return Required";
    $body = "Dear " . $row['full_name'] . ",<br><br>The book '<strong>" . $row['title'] . "</strong>' (" . $row['book_uid'] . ") was due on <strong>" . date('d M Y', $due_date_ts) . "</strong>.<br><br><span style='color:red; font-weight:bold;'>It is now overdue by $overdue_days day(s).</span><br><br>Please return it immediately to " . ($row['library_name'] ?? 'the library') . " to stop accumulating further fines.";
    
    if(send_system_email($row['email'], $row['full_name'], $subject, $body, $row['library_name'])) {
        echo "Sent overdue reminder to " . $row['email'] . "\n";
        $sent_count++;
    }
}
$stmt->close();

echo "Process Complete. Total Emails Sent: $sent_count\n";
close_db_connection($conn);
?>