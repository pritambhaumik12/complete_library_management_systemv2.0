<?php
/**
 * Daily Cron Job: Send Reminder Emails for Outstanding Fines
 * Run this script daily via server cron.
 */

// Adjust paths as this file is inside /admin/
require_once '../includes/db_connect.php';
require_once '../includes/functions.php';

global $conn;

echo "Starting Daily Fine Reminder Check...\n";

$inst_init = get_setting($conn, 'institution_initials') ?: 'INS';
$currency = get_setting($conn, 'currency_symbol');

// 1. Fetch all outstanding fines grouped by Member
// We fetch member details and a GROUP_CONCAT of their fine details to send a single digest email.
$sql = "
    SELECT 
        tm.member_id, tm.full_name, tm.email, tm.member_uid,
        GROUP_CONCAT(
            CONCAT(
                tf.fine_id, '::', 
                tf.fine_amount, '::', 
                tf.fine_type, '::', 
                COALESCE(tb.title, 'Manual Fine'), '::', 
                COALESCE(l.library_name, 'General'), '::', 
                COALESCE(l.library_initials, 'LIB')
            ) SEPARATOR '||'
        ) as fine_data
    FROM tbl_fines tf
    JOIN tbl_members tm ON tf.member_id = tm.member_id
    LEFT JOIN tbl_circulation tc ON tf.circulation_id = tc.circulation_id
    LEFT JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
    LEFT JOIN tbl_books tb ON tbc.book_id = tb.book_id
    LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
    WHERE tf.payment_status = 'Pending' AND tm.status = 'Active' AND tm.email IS NOT NULL AND tm.email != ''
    GROUP BY tm.member_id
";

$result = $conn->query($sql);
$sent_count = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $member_name = $row['full_name'];
        $email = $row['email'];
        $fines_raw = explode('||', $row['fine_data']);
        
        $total_due = 0;
        $fine_table_rows = "";
        $primary_library = "Central Library"; // Default context for email branding

        foreach ($fines_raw as $f_str) {
            // Parse concatenated string: ID::Amount::Type::Book::LibName::LibInit
            list($fid, $amt, $type, $book, $libName, $libInit) = explode('::', $f_str);
            
            $total_due += $amt;
            $primary_library = $libName; // Use the library of the last fine found as context
            
            // Format Fine ID: {INST}/{LIB}/FINE/{ID}
            $formatted_id = strtoupper("$inst_init/$libInit/FINE/$fid");
            
            $fine_table_rows .= "
                <tr>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>$formatted_id</td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>$type <br><small style='color:#666;'>$book</small></td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee; color: #ef4444; font-weight:bold;'>$currency$amt</td>
                </tr>
            ";
        }

        // Construct Email Body
        $subject = "Outstanding Fine Reminder - Action Required";
        $body = "
            <h2 style='color: #ef4444;'>Outstanding Fines Notice</h2>
            <p>Dear $member_name,</p>
            <p>This is a reminder that you have outstanding fines totaling <strong>$currency$total_due</strong> associated with your library account.</p>
            
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px;'>
                <tr style='background: #f3f4f6; text-align: left;'>
                    <th style='padding: 8px;'>Fine ID</th>
                    <th style='padding: 8px;'>Details</th>
                    <th style='padding: 8px;'>Amount</th>
                </tr>
                $fine_table_rows
                <tr style='background: #fee2e2;'>
                    <td colspan='2' style='padding: 10px; font-weight: bold; text-align: right;'>Total Due:</td>
                    <td style='padding: 10px; font-weight: bold; color: #b91c1c;'>$currency$total_due</td>
                </tr>
            </table>
            
            <p>Please visit the <strong>$primary_library</strong> circulation desk or log in to your account to clear these dues immediately to avoid account suspension.</p>
        ";

        // Send Email
        if (send_system_email($email, $member_name, $subject, $body, $primary_library)) {
            $sent_count++;
            echo "Reminder sent to $member_name ($email)\n";
        } else {
            echo "Failed to send to $member_name\n";
        }
    }
}

echo "Done. Sent $sent_count reminder emails.\n";
close_db_connection($conn);
?>