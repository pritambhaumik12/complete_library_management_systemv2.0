<?php
/**
 * Daily Cron Job: Send Reminder Emails for Unpaid Fines
 * Run this script daily via server cron.
 * Usage (Command Line): php /path/to/your/site/admin/cron_fine_reminders.php
 */

// 1. Enable Error Reporting for Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. Define Log File Path
$log_file = dirname(__DIR__) . '/data/cron_fines_debug.log';

function write_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[$timestamp] $message" . PHP_EOL;
    echo $formatted_message; // For manual runs
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

write_log("--- Starting Fine Reminder Process ---");

// 3. Fix Include Paths (CRITICAL FIX)
// Switch context to 'includes' directory so functions.php loads PHPMailer correctly
$current_dir = getcwd();
$includes_dir = dirname(__DIR__) . '/includes';

if (is_dir($includes_dir)) {
    chdir($includes_dir);
    
    if (file_exists('db_connect.php')) {
        require_once 'db_connect.php';
    } else {
        write_log("CRITICAL ERROR: db_connect.php not found in $includes_dir");
        exit;
    }
    
    if (file_exists('functions.php')) {
        require_once 'functions.php';
    } else {
        write_log("CRITICAL ERROR: functions.php not found in $includes_dir");
        exit;
    }
    
    // Switch back to original directory
    chdir($current_dir);
} else {
    write_log("CRITICAL ERROR: Includes directory not found at $includes_dir");
    exit;
}

global $conn;

if (!$conn || $conn->connect_error) {
    write_log("Database Connection Failed.");
    exit;
}

// --- Fetch Settings ---
$currency = get_setting($conn, 'currency_symbol') ?? '';

// --- Helper: Generate Fine Email Card ---
function generate_fine_email_card($data, $currency) {
    // Styling constants
    $color = '#dc2626'; // Red for urgent financial matters
    $bg_light = '#fef2f2';
    
    $formatted_amount = $currency . ' ' . number_format($data['fine_amount'], 2);
    $library_name = htmlspecialchars($data['library_name'] ?? 'The Central Library');
    $fine_date = date('d M Y', strtotime($data['created_at']));
    $remarks = !empty($data['remarks']) ? htmlspecialchars($data['remarks']) : 'Overdue Books / General Fine';

    $html = "
    <div style='font-family: \"Segoe UI\", sans-serif; color: #334155;'>
        <h2 style='color: $color; margin-top: 0;'>Outstanding Fine Notice</h2>
        <p style='font-size: 15px; line-height: 1.6;'>Dear <strong>" . htmlspecialchars($data['full_name']) . "</strong>,</p>
        <p style='font-size: 15px; line-height: 1.6;'>Our records indicate that you have an unpaid fine pending on your account. Please settle this amount at your earliest convenience to maintain your borrowing privileges.</p>
        
        <div style='background: #fff; border: 1px solid #e2e8f0; border-left: 5px solid $color; border-radius: 8px; padding: 20px; margin: 25px 0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);'>
            
            <div style='display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #cbd5e1; padding-bottom:15px; margin-bottom:15px;'>
                <span style='font-size:14px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;'>Total Amount Due</span>
                <span style='font-size:24px; color:$color; font-weight:800;'>$formatted_amount</span>
            </div>

            <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                <tr>
                    <td style='padding: 8px 0; color: #64748b; width: 40%;'><strong>Fine Reference ID:</strong></td>
                    <td style='padding: 8px 0; color: #334155; font-family: monospace; font-size: 15px;'>" . htmlspecialchars($data['fine_uid']) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'><strong>Reason (Remarks):</strong></td>
                    <td style='padding: 8px 0; color: #334155; font-weight:500;'>$remarks</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'><strong>Fined From (Date):</strong></td>
                    <td style='padding: 8px 0; color: #334155;'>$fine_date</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'><strong>Fine Created In:</strong></td>
                    <td style='padding: 8px 0; color: #334155; font-weight:600;'>$library_name</td>
                </tr>
            </table>
        </div>

        <div style='background-color: $bg_light; border: 1px solid " . str_replace(')', ', 0.2)', str_replace('rgb', 'rgba', $color)) . "; color: #991b1b; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 20px;'>
            <strong>Payment Instruction:</strong><br>
            Please visit <strong>$library_name</strong> immediately to pay this fine.
        </div>
    </div>";

    return $html;
}

// --- Fetch Unpaid Fines ---
// We join with tbl_libraries to get the name of the library where the fine was created
// We assume tbl_fines has library_id, or we fallback to user's library if null
write_log("Fetching unpaid fines...");

$sql = "SELECT tf.fine_uid, tf.fine_amount, tf.remarks, tf.created_at, 
               tm.full_name, tm.email, 
               tl.library_name 
        FROM tbl_fines tf 
        JOIN tbl_members tm ON tf.member_id = tm.member_id 
        LEFT JOIN tbl_libraries tl ON tf.library_id = tl.library_id 
        WHERE tf.status = 'Unpaid' AND tm.email IS NOT NULL AND tm.email != ''";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    
    $count = 0;
    while($row = $res->fetch_assoc()) {
        $subject = "Outstanding Fine Notice: " . $row['fine_uid'];
        $body = generate_fine_email_card($row, $currency);
        $lib_name = $row['library_name']; // Pass to wrapper for header branding
        
        write_log("Sending fine notice to: " . $row['email']);
        
        if (send_system_email($row['email'], $row['full_name'], $subject, $body, $lib_name)) {
            $count++;
        } else {
            write_log("FAILED to send to " . $row['email']);
        }
    }
    
    if ($count == 0) write_log("No unpaid fines with valid emails found.");
    write_log("Total Emails Sent: $count");
    
    $stmt->close();
} else {
    write_log("SQL Prepare Error: " . $conn->error);
}

close_db_connection($conn);
?>
