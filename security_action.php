<?php
// File: complete_library_management_systemv3.0/security_action.php

// Include functions to access database connection ($conn) and email function (send_system_email)
require_once 'includes/functions.php';

header('Content-Type: application/json');

// Get JSON input from the read_online.php AJAX call
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['action']) && $data['action'] === 'log_breach') {
    $member_id = $_SESSION['member_id'] ?? 0;
    $book_id = (int)($data['book_id'] ?? 0);
    
    if ($member_id > 0) {
        // --- 1. Fetch Member Email & Details (Before destroying session) ---
        // We need this to send the email notification
        $stmt_mem = $conn->prepare("SELECT full_name, email, member_uid FROM tbl_members WHERE member_id = ?");
        $stmt_mem->bind_param("i", $member_id);
        $stmt_mem->execute();
        $mem_data = $stmt_mem->get_result()->fetch_assoc();
        $stmt_mem->close();

        // --- 2. Update Member: Increment violations AND set status to Inactive ---
        $update_stmt = $conn->prepare("UPDATE tbl_members SET screenshot_violations = screenshot_violations + 1, status = 'Inactive' WHERE member_id = ?");
        $update_stmt->bind_param("i", $member_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        // --- 3. Create System Alert Log ---
        $msg = "User attempted to take a screenshot (PrintScreen key detected).";
        $alert_stmt = $conn->prepare("INSERT INTO tbl_system_alerts (member_id, book_id, message) VALUES (?, ?, ?)");
        $alert_stmt->bind_param("iis", $member_id, $book_id, $msg);
        $alert_stmt->execute();
        $alert_stmt->close();
        
        // --- 4. Send Security Violation Email ---
        if ($mem_data && !empty($mem_data['email'])) {
            $subject = "Security Alert: Account Locked";
            
            // Construct a professional email body
            $body = "
                <div style='font-family: Arial, sans-serif; color: #333;'>
                    <h2 style='color: #d90429;'>Security Violation Detected</h2>
                    <p>Dear <strong>" . htmlspecialchars($mem_data['full_name']) . "</strong> (" . htmlspecialchars($mem_data['member_uid']) . "),</p>
                    
                    <p>Your library account has been <strong>temporarily locked</strong> because our system detected a security violation during your recent reading session.</p>
                    
                    <div style='background-color: #fff5f5; border-left: 4px solid #d90429; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0; color: #9b2c2c;'><strong>Violation Type:</strong> Content Protection Breach</p>
                        <p style='margin: 5px 0 0; color: #9b2c2c;'><strong>Details:</strong> Unauthorized Screenshot or Screen Capture Attempt</p>
                        <p style='margin: 5px 0 0; color: #666; font-size: 0.9em;'>Time: " . date('d M Y, h:i A') . "</p>
                    </div>

                    <p>As per our Digital Content Policy, capturing protected content is strictly prohibited. To reactivate your account, please contact the Library Administrator.</p>
                </div>
            ";

            // Send the email
            send_system_email($mem_data['email'], $mem_data['full_name'], $subject, $body);
        }

        // --- 5. Destroy Session to logout user ---
        session_unset();
        session_destroy();
        
        echo json_encode(['status' => 'success', 'message' => 'Account locked.']);
        exit;
    }
}

echo json_encode(['status' => 'error']);
exit;
?>