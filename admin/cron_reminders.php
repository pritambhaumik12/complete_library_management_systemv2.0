<?php
/**
 * Daily Cron Job: Send Reminder Emails for Book Returns
 * Run this script daily via server cron.
 * Usage (Command Line): php /path/to/your/site/admin/cron_reminders.php
 */

// 1. Enable Error Reporting for Debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 2. Define Log File Path (../data/cron_debug.log)
$log_file = dirname(__DIR__) . '/data/cron_debug.log';

function write_log($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $formatted_message = "[$timestamp] $message" . PHP_EOL;
    
    // Output to screen (for manual runs)
    echo $formatted_message;
    
    // Output to file
    file_put_contents($log_file, $formatted_message, FILE_APPEND);
}

write_log("--- Starting Daily Reminder Process ---");

// 3. Fix Include Paths (CRITICAL FIX)
// We switch context to the 'includes' directory so functions.php can find PHPMailer
$current_dir = getcwd();
$includes_dir = dirname(__DIR__) . '/includes';

if (is_dir($includes_dir)) {
    chdir($includes_dir);
    
    // Require files from the perspective of the includes folder
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
    
    // Switch back to original directory (admin)
    chdir($current_dir);
} else {
    write_log("CRITICAL ERROR: Includes directory not found at $includes_dir");
    exit;
}

global $conn;

if (!$conn || $conn->connect_error) {
    write_log("Database Connection Failed: " . ($conn ? $conn->connect_error : "Object is null"));
    exit;
}

// --- Fetch System Settings for Calculations ---
$fine_per_day = (float)get_setting($conn, 'fine_per_day');
$currency = get_setting($conn, 'currency_symbol') ?? ''; // e.g., $ or ₹
if(empty($currency)) $currency = '';

$sent_count = 0;
$today = date('Y-m-d');

// Helper function to generate standardized HTML card
function generate_email_card($type, $data, $currency, $fine_per_day, $days_diff) {
    // Colors and Titles based on type
    switch ($type) {
        case 'upcoming':
            $color = '#059669'; // Green/Teal
            $bg_light = '#ecfdf5';
            $title = "Reminder: Due Soon";
            $msg = "This is a friendly reminder that the following book is due for return soon.";
            $late_text = "Due In";
            $late_val = "$days_diff Day(s)";
            $show_fine = false;
            break;
        case 'today':
            $color = '#d97706'; // Amber/Orange
            $bg_light = '#fffbeb';
            $title = "Action Required: Due Today";
            $msg = "This is an urgent reminder that the following book is due <strong>TODAY</strong>.";
            $late_text = "Status";
            $late_val = "Due Today";
            $show_fine = false;
            break;
        case 'overdue':
            $color = '#dc2626'; // Red
            $bg_light = '#fef2f2';
            $title = "OVERDUE NOTICE";
            $msg = "We haven't received the book below yet. It is now <strong style='color:$color'>OVERDUE</strong>.";
            $late_text = "Late By";
            $late_val = "$days_diff Day(s)";
            $show_fine = true;
            break;
        default:
            $color = '#4b5563'; $bg_light = '#f3f4f6'; $title = "Notice"; $msg=""; $show_fine=false; $late_text=""; $late_val="";
    }

    $total_fine = $show_fine ? ($days_diff * $fine_per_day) : 0;
    
    // HTML Template
    $html = "
    <div style='font-family: \"Segoe UI\", sans-serif; color: #334155;'>
        <h2 style='color: $color; margin-top: 0;'>$title</h2>
        <p style='font-size: 15px; line-height: 1.6;'>Dear <strong>" . htmlspecialchars($data['full_name']) . "</strong>,</p>
        <p style='font-size: 15px; line-height: 1.6;'>$msg</p>
        
        <div style='background: #fff; border: 1px solid #e2e8f0; border-left: 5px solid $color; border-radius: 8px; padding: 20px; margin: 25px 0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);'>
            <h3 style='margin: 0 0 15px 0; color: #1e293b; font-size: 18px;'>" . htmlspecialchars($data['title']) . "</h3>
            
            <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                <tr>
                    <td style='padding: 8px 0; color: #64748b; width: 40%;'><strong>Book Copy ID:</strong></td>
                    <td style='padding: 8px 0; color: #334155; font-family: monospace; font-size: 15px;'>" . htmlspecialchars($data['book_uid']) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'><strong>Original Due Date:</strong></td>
                    <td style='padding: 8px 0; color: #334155;'>" . date('d M Y', strtotime($data['due_date'])) . "</td>
                </tr>
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'><strong>$late_text:</strong></td>
                    <td style='padding: 8px 0; color: $color; font-weight: bold;'>$late_val</td>
                </tr>";
                
    if ($show_fine) {
        $html .= "
                <tr>
                    <td style='padding: 8px 0; color: #64748b;'><strong>Fine Per Day:</strong></td>
                    <td style='padding: 8px 0; color: #334155;'>$currency " . number_format($fine_per_day, 2) . "</td>
                </tr>
                <tr style='border-top: 1px dashed #cbd5e1;'>
                    <td style='padding: 12px 0 0 0; font-size: 15px; color: #1e293b;'><strong>Total Fine (Estimated):</strong></td>
                    <td style='padding: 12px 0 0 0; font-size: 16px; color: $color; font-weight: 800;'>$currency " . number_format($total_fine, 2) . "</td>
                </tr>";
    }

    $html .= "
            </table>
        </div>

        <div style='background-color: $bg_light; border: 1px solid " . str_replace(')', ', 0.2)', str_replace('rgb', 'rgba', $color)) . "; color: #1e293b; padding: 15px; border-radius: 6px; text-align: center; margin-bottom: 20px;'>
            <strong>Action Required:</strong> Please return this book immediately to:
            <div style='font-size: 16px; font-weight: bold; margin-top: 5px; color: $color;'>" . htmlspecialchars($data['library_name'] ?? 'The Library') . "</div>
        </div>
    </div>";

    return $html;
}


// --- 1. UPCOMING REMINDERS ---
$days_setting = get_setting($conn, 'reminder_days');
write_log("Settings Check: Reminder Days = '$days_setting'");

if (!empty($days_setting)) {
    $days_array = explode(',', $days_setting);
    foreach ($days_array as $day_offset) {
        $day_offset = (int)trim($day_offset);
        if ($day_offset <= 0) continue;

        $target_date = date('Y-m-d', strtotime("+$day_offset days"));
        write_log("Checking for books due on: $target_date (In $day_offset days)");
        
        $sql = "SELECT tc.due_date, tm.full_name, tm.email, tb.title, tbc.book_uid, l.library_name 
                FROM tbl_circulation tc 
                JOIN tbl_members tm ON tc.member_id = tm.member_id
                JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                JOIN tbl_books tb ON tbc.book_id = tb.book_id
                LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                WHERE tc.status = 'Issued' AND DATE(tc.due_date) = ? AND tm.email IS NOT NULL AND tm.email != ''";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("s", $target_date);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $found = 0;
            while($row = $res->fetch_assoc()) {
                $found++;
                $subject = "Reminder: Book Due in $day_offset Day(s)";
                $body = generate_email_card('upcoming', $row, $currency, $fine_per_day, $day_offset);
                
                write_log("Sending Upcoming ($day_offset days) email to: " . $row['email']);
                
                // Note: send_system_email wrapper handles Institution Logo and Name automatically
                if(send_system_email($row['email'], $row['full_name'], $subject, $body, $row['library_name'])) {
                    $sent_count++;
                } else {
                    write_log("FAILED to send email to " . $row['email']);
                }
            }
            if ($found == 0) write_log("No books found due in $day_offset days.");
            $stmt->close();
        } else {
            write_log("SQL Prepare Error (Upcoming): " . $conn->error);
        }
    }
}

// --- 2. DUE TODAY REMINDERS ---
write_log("Checking for books due TODAY ($today)");
$sql_today = "SELECT tc.due_date, tm.full_name, tm.email, tb.title, tbc.book_uid, l.library_name 
              FROM tbl_circulation tc 
              JOIN tbl_members tm ON tc.member_id = tm.member_id
              JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
              JOIN tbl_books tb ON tbc.book_id = tb.book_id
              LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
              WHERE tc.status = 'Issued' AND DATE(tc.due_date) = ? AND tm.email IS NOT NULL AND tm.email != ''";

$stmt = $conn->prepare($sql_today);
if ($stmt) {
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $found_today = 0;
    while($row = $res->fetch_assoc()) {
        $found_today++;
        $subject = "Action Required: Book Due Today";
        $body = generate_email_card('today', $row, $currency, $fine_per_day, 0);
        
        write_log("Sending Due Today email to: " . $row['email']);
        
        if(send_system_email($row['email'], $row['full_name'], $subject, $body, $row['library_name'])) {
            $sent_count++;
        } else {
            write_log("FAILED to send email to " . $row['email']);
        }
    }
    if ($found_today == 0) write_log("No books found due today.");
    $stmt->close();
}

// --- 3. OVERDUE REMINDERS ---
write_log("Checking for OVERDUE books (Due before $today)");
$sql_overdue = "SELECT tc.due_date, tm.full_name, tm.email, tb.title, tbc.book_uid, l.library_name 
                FROM tbl_circulation tc 
                JOIN tbl_members tm ON tc.member_id = tm.member_id
                JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id
                JOIN tbl_books tb ON tbc.book_id = tb.book_id
                LEFT JOIN tbl_libraries l ON tb.library_id = l.library_id
                WHERE tc.status = 'Issued' AND DATE(tc.due_date) < ? AND tm.email IS NOT NULL AND tm.email != ''";

$stmt = $conn->prepare($sql_overdue);
if ($stmt) {
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $found_overdue = 0;
    while($row = $res->fetch_assoc()) {
        $found_overdue++;
        $due_date_ts = strtotime($row['due_date']);
        $overdue_days = floor((time() - $due_date_ts) / (60 * 60 * 24));
        
        $subject = "OVERDUE NOTICE: Immediate Return Required";
        $body = generate_email_card('overdue', $row, $currency, $fine_per_day, $overdue_days);
        
        write_log("Sending Overdue ($overdue_days days) email to: " . $row['email']);
        
        if(send_system_email($row['email'], $row['full_name'], $subject, $body, $row['library_name'])) {
            $sent_count++;
        } else {
            write_log("FAILED to send email to " . $row['email']);
        }
    }
    if ($found_overdue == 0) write_log("No overdue books found.");
    $stmt->close();
}

write_log("Process Complete. Total Emails Sent: $sent_count");
close_db_connection($conn);
?>
