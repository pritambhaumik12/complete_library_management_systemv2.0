<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';
$is_logged_in_super_admin = is_super_admin($conn); // Permission control
$show_bulk_preview = false; 
$bulk_data = []; 

// --- Fetch Libraries for Dropdowns ---
$libraries = [];
$lib_sql = "SELECT library_id, library_name FROM tbl_libraries ORDER BY library_name ASC";
$lib_result = $conn->query($lib_sql);
while($row = $lib_result->fetch_assoc()) {
    $libraries[] = $row;
}

// --- HELPER: Send Account Email ---
function send_account_email($member_data, $password, $context = 'Updated') {
    // 1. Prepare QR Code: Attempt Base64 embedding for maximum email client compatibility
    $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($member_data['member_uid']);
    $qr_image_data = @file_get_contents($qr_api_url);
    $qr_src = '';
    
    if ($qr_image_data !== false) {
        $qr_base64 = base64_encode($qr_image_data);
        // Use Base64 data URI scheme for embedding
        $qr_src = 'data:image/png;base64,' . $qr_base64;
    } else {
        // Fallback to the direct URL if fetching fails (in case allow_url_fopen is disabled)
        $qr_src = $qr_api_url;
    }

    $subject = "Library Account " . $context;
    
    // HTML Email Body
    $body = '
    <div style="font-family: Arial, sans-serif; color: #333; line-height: 1.6;">
        <h2 style="color: #4f46e5;">Library Account Details</h2>
        <p>Dear <strong>' . htmlspecialchars($member_data['full_name']) . '</strong>,</p>
        <p>Your library account has been ' . strtolower($context) . '. Please find your details below:</p>
        
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0; border: 1px solid #eee;">
            <tr style="background-color: #f9fafb;">
                <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; width: 150px;">Member ID:</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">' . htmlspecialchars($member_data['member_uid']) . '</td>
                <td rowspan="4" style="padding: 10px; border-bottom: 1px solid #eee; text-align: center; width: 160px; background: #fff;">
                    <img src="' . $qr_src . '" alt="Member QR Code" style="width: 120px; height: 120px; border: 1px solid #ccc; padding: 5px; display: block; margin: 0 auto;">
                    <br><small style="color: #666;">Scan ID</small>
                </td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;">Full Name:</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">' . htmlspecialchars($member_data['full_name']) . '</td>
            </tr>
            <tr style="background-color: #f9fafb;">
                <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;">Department:</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">' . htmlspecialchars($member_data['department']) . '</td>
            </tr>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold;">Email:</td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">' . htmlspecialchars($member_data['email']) . '</td>
            </tr>
        </table>

        <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 15px; border-radius: 8px; margin-top: 20px;">
            <p style="margin: 0 0 10px 0; font-weight: bold; color: #1e40af;">Login Credentials</p>
            
            <details>
                <summary style="cursor: pointer; color: #2563eb; font-weight: 600; outline: none; list-style: none; /* Hide default marker */ display: block;">
                    <span style="display: inline-block; width: 0; height: 0; border-style: solid; border-width: 5px 0 5px 8px; border-color: transparent transparent transparent #2563eb; margin-right: 5px; vertical-align: middle;"></span>
                    <span style="vertical-align: middle;">Click here to View Password</span>
                </summary>
                <div style="margin-top: 10px; padding: 10px; background: #fff; border: 1px dashed #ccc; display: inline-block; border-radius: 4px;">
                    <span style="font-family: monospace; font-size: 16px; color: #333;">' . htmlspecialchars($password) . '</span>
                </div>
            </details>
            
            <style>
                /* Ensures default browser details marker is hidden for better design */
                details > summary::marker, 
                details > summary::-webkit-details-marker {
                    display: none;
                    content: "";
                }
            </style>
        </div>

        <p style="margin-top: 20px; font-size: 0.9em; color: #666;">Please keep your credentials safe. If you did not request this change, please contact the library administration immediately.</p>
        <p style="font-size: 0.8em; color: #999;">Note: The "Click to View Password" feature relies on your email client supporting the HTML &lt;details&gt; tag. If the password is visible immediately, your client does not support this feature.</p>
    </div>';

    // Use the existing send_system_email function (assuming it handles the underlying mailer logic)
    send_system_email($member_data['email'], $member_data['full_name'], $subject, $body);
}

// --- Handle User Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. RESET PASSWORD
    if ($action === 'reset_password') {
        $target_id = (int)($_POST['target_id'] ?? 0);
        $target_type = $_POST['target_type'] ?? ''; 
        $new_password = trim($_POST['new_password'] ?? '');

        if (empty($new_password)) {
            $error = "New password cannot be empty.";
        } else {
            if ($target_type === 'member') {
                $stmt = $conn->prepare("UPDATE tbl_members SET password = ? WHERE member_id = ?");
                $stmt->bind_param("si", $new_password, $target_id);
                if ($stmt->execute()) {
                    $message = "Member password updated.";
                    
                    // Fetch member details for email
                    $stmt_get = $conn->prepare("SELECT * FROM tbl_members WHERE member_id = ?");
                    $stmt_get->bind_param("i", $target_id);
                    $stmt_get->execute();
                    $member_data = $stmt_get->get_result()->fetch_assoc();
                    
                    if ($member_data && !empty($member_data['email'])) {
                        send_account_email($member_data, $new_password, 'Password Updated');
                    }
                } else {
                    $error = "Error updating password.";
                }
            } elseif ($target_type === 'admin') {
                // Security: Only Super Admin can reset Admin passwords
                if ($is_logged_in_super_admin) {
                    $stmt = $conn->prepare("UPDATE tbl_admin SET password = ? WHERE admin_id = ?");
                    $stmt->bind_param("si", $new_password, $target_id);
                    if ($stmt->execute()) $message = "Admin password updated.";
                    else $error = "Error updating password.";
                } else {
                    $error = "Access Denied.";
                }
            }
        }
    }
    
    // 2. ADD USER (Single)
    elseif ($action === 'add_user') {
        $account_type = $_POST['account_type'] ?? 'member';
        $full_name = trim($_POST['full_name'] ?? '');
        $username_or_uid = trim($_POST['member_uid'] ?? ''); 
        $password = trim($_POST['password'] ?? 'password'); 

        if (empty($full_name) || empty($username_or_uid)) {
            $error = "Name and ID are required.";
        } else {
            if ($account_type === 'member') {
                $email = trim($_POST['email'] ?? '');
                $department = trim($_POST['department'] ?? '');
                if (empty($department)) {
                     $error = "Department is required.";
                } else {
                    $stmt_check = $conn->prepare("SELECT member_id FROM tbl_members WHERE member_uid = ?");
                    $stmt_check->bind_param("s", $username_or_uid);
                    $stmt_check->execute();
                    if ($stmt_check->get_result()->num_rows > 0) {
                        $error = "Member ID already exists.";
                    } else {
                        $stmt = $conn->prepare("INSERT INTO tbl_members (member_uid, password, full_name, email, department) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssss", $username_or_uid, $password, $full_name, $email, $department);
                        if ($stmt->execute()) {
                            $message = "Member added successfully.";
                            
                            // Prepare data for email
                            $member_data = [
                                'full_name' => $full_name,
                                'member_uid' => $username_or_uid,
                                'email' => $email,
                                'department' => $department
                            ];
                            if (!empty($email)) {
                                send_account_email($member_data, $password, 'Account Created');
                            }
                        } else {
                            $error = "Error adding member.";
                        }
                    }
                }
            } elseif ($account_type === 'admin') {
                // Security: Only Super Admin can add Admin
                if (!$is_logged_in_super_admin) {
                    $error = "Access Denied.";
                } else {
                    $is_super = (isset($_POST['is_super_admin'])) ? 1 : 0;
                    $library_id = (isset($_POST['library_id'])) ? (int)$_POST['library_id'] : 0;
                    if ($library_id === 0) $library_id = NULL; 

                    $stmt_check = $conn->prepare("SELECT admin_id FROM tbl_admin WHERE username = ?");
                    $stmt_check->bind_param("s", $username_or_uid);
                    $stmt_check->execute();
                    if ($stmt_check->get_result()->num_rows > 0) {
                        $error = "Username exists.";
                    } else {
                        $stmt = $conn->prepare("INSERT INTO tbl_admin (username, password, full_name, is_super_admin, library_id) VALUES (?, ?, ?, ?, ?)");
                        $stmt->bind_param("sssis", $username_or_uid, $password, $full_name, $is_super, $library_id);
                        if ($stmt->execute()) $message = "Admin added successfully.";
                        else $error = "Error adding admin: " . $conn->error;
                    }
                }
            }
        }
    }
    
    // 3. EDIT MEMBER
    elseif ($action === 'edit_member') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $status = isset($_POST['status']) && $_POST['status'] === 'Active' ? 'Active' : 'Inactive';

        if (empty($full_name) || empty($department)) {
            $error = "Name and Department are required.";
        } else {
            $stmt = $conn->prepare("UPDATE tbl_members SET full_name = ?, email = ?, department = ?, status = ? WHERE member_id = ?");
            $stmt->bind_param("ssssi", $full_name, $email, $department, $status, $member_id);
            if ($stmt->execute()) {
                $message = "Member updated successfully.";
                
                // Fetch current data (including password) for email
                $stmt_get = $conn->prepare("SELECT * FROM tbl_members WHERE member_id = ?");
                $stmt_get->bind_param("i", $member_id);
                $stmt_get->execute();
                $member_data = $stmt_get->get_result()->fetch_assoc();
                
                if ($member_data && !empty($member_data['email'])) {
                    send_account_email($member_data, $member_data['password'], 'Account Updated');
                }
            } else {
                $error = "Error updating member.";
            }
        }
    } 

    // 4. EDIT ADMIN
    elseif ($action === 'edit_admin') {
        if (!$is_logged_in_super_admin) {
            $error = "Access Denied. Only Super Admins can edit other admin accounts.";
        } else {
            $admin_id = (int)($_POST['admin_id'] ?? 0);
            $full_name = trim($_POST['full_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $is_super = isset($_POST['is_super_admin']) ? 1 : 0;
            $library_id = (int)($_POST['library_id'] ?? 0);
            if ($library_id === 0) $library_id = NULL;

            if (empty($full_name) || empty($username)) {
                $error = "Full Name and Username are required.";
            } else {
                $stmt_check = $conn->prepare("SELECT admin_id FROM tbl_admin WHERE username = ? AND admin_id != ?");
                $stmt_check->bind_param("si", $username, $admin_id);
                $stmt_check->execute();
                if ($stmt_check->get_result()->num_rows > 0) {
                    $error = "Username already taken.";
                } else {
                    $stmt = $conn->prepare("UPDATE tbl_admin SET full_name = ?, username = ?, is_super_admin = ?, library_id = ? WHERE admin_id = ?");
                    $stmt->bind_param("ssisi", $full_name, $username, $is_super, $library_id, $admin_id);
                    if ($stmt->execute()) $message = "Admin details updated successfully.";
                    else $error = "Error updating admin.";
                }
            }
        }
    }

    // 5. DELETE MEMBER
    elseif ($action === 'delete_member') {
        $member_id = (int)($_POST['member_id'] ?? 0);
        
        // STRICT VALIDATION CHECK BEFORE DELETION
        $stmt_check = $conn->prepare("
            SELECT 
                (SELECT COUNT(*) FROM tbl_circulation WHERE member_id = ? AND status IN ('Issued', 'Overdue')) AS loan_count,
                (SELECT COUNT(*) FROM tbl_fines WHERE member_id = ? AND payment_status = 'Pending') AS fine_count,
                (SELECT COUNT(*) FROM tbl_reservations WHERE member_id = ? AND status IN ('Pending', 'Accepted')) AS res_count
        ");
        $stmt_check->bind_param("iii", $member_id, $member_id, $member_id);
        $stmt_check->execute();
        $status_result = $stmt_check->get_result()->fetch_assoc();
        
        $loan_count = (int)$status_result['loan_count'];
        $fine_count = (int)$status_result['fine_count'];
        $res_count = (int)$status_result['res_count'];

        if ($loan_count > 0 || $fine_count > 0 || $res_count > 0) {
            $error_parts = [];
            if ($loan_count > 0) $error_parts[] = "**{$loan_count}** active/overdue loans";
            if ($fine_count > 0) $error_parts[] = "**{$fine_count}** pending fines";
            if ($res_count > 0) $error_parts[] = "**{$res_count}** active reservations";
            
            $error = "Cannot delete member. Outstanding items found: " . implode(", ", $error_parts) . ". Please clear them first.";
        } else {
            // Safe to proceed
            $conn->begin_transaction();
            try {
                $stmt_del = $conn->prepare("DELETE FROM tbl_members WHERE member_id = ?");
                $stmt_del->bind_param("i", $member_id);
                
                if ($stmt_del->execute()) {
                    $conn->commit();
                    $message = "Member deleted successfully.";
                } else {
                    throw new Exception($conn->error);
                }
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Error deleting member: " . $e->getMessage();
            }
        }
    }

    // 6. DELETE ADMIN
    elseif ($action === 'delete_admin') {
        if (!$is_logged_in_super_admin) {
            $error = "Access Denied.";
        } else {
            $admin_id = (int)($_POST['admin_id'] ?? 0);
            if ($admin_id == $_SESSION['admin_id']) {
                $error = "You cannot delete your own account.";
            } else {
                $conn->query("DELETE FROM tbl_admin WHERE admin_id = $admin_id");
                $message = "Admin deleted successfully.";
            }
        }
    }

    // 7. BULK PREVIEW
    elseif ($action === 'preview_bulk') {
        if (isset($_FILES['bulk_file']) && $_FILES['bulk_file']['error'] == 0) {
            $file = $_FILES['bulk_file']['tmp_name'];
            $handle = fopen($file, "r");
            fgetcsv($handle); // Skip header
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if(count($data) >= 5) {
                    // Assumes CSV format: Type, Full Name, MemberID, Email, Dept
                    $bulk_data[] = [
                        'type' => trim($data[0]),
                        'name' => trim($data[1]),
                        'uid' => trim($data[2]),
                        'email' => trim($data[3]),
                        'dept' => trim($data[4]),
                        'default_pass' => $_POST['default_password'] ?? 'password'
                    ];
                }
            }
            fclose($handle);
            $show_bulk_preview = true; // Trigger the modal
        } else {
            $error = "Error uploading file. Please check the file format (CSV).";
        }
    }

    // 8. CONFIRM BULK
    elseif ($action === 'confirm_bulk') {
        $raw_data = $_POST['bulk_data'] ?? [];
        $success_count = 0; 
        $fail_count = 0;
        
        $conn->begin_transaction();
        try {
            foreach ($raw_data as $row) {
                $type = $row['type'];
                $name = trim($row['name']);
                $uid = trim($row['uid']);
                $email = trim($row['email']);
                $dept = trim($row['dept']);
                $pass = trim($row['default_pass']);

                if (empty($uid) || empty($name)) { $fail_count++; continue; }

                if (strtolower($type) === 'member') {
                    $chk = $conn->prepare("SELECT member_id FROM tbl_members WHERE member_uid = ?");
                    $chk->bind_param("s", $uid); 
                    $chk->execute();
                    
                    if ($chk->get_result()->num_rows == 0) {
                        $stmt = $conn->prepare("INSERT INTO tbl_members (member_uid, full_name, email, department, password, status) VALUES (?, ?, ?, ?, ?, 'Active')");
                        $stmt->bind_param("sssss", $uid, $name, $email, $dept, $pass);
                        if($stmt->execute()) {
                            $success_count++;
                            // Send email for bulk creation
                             if (!empty($email)) {
                                 $m_data = ['full_name'=>$name, 'member_uid'=>$uid, 'email'=>$email, 'department'=>$dept];
                                 send_account_email($m_data, $pass, 'Account Created');
                             }
                        } else {
                            $fail_count++;
                        }
                    } else {
                        $fail_count++; // Duplicate ID
                    }
                }
            }
            $conn->commit();
            $message = "Bulk Process Complete: **$success_count** created, **$fail_count** failed (duplicates/errors).";
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Bulk upload transaction failed: " . $e->getMessage();
        }
    }
}

// --- Fetch Data ---
$search_query = trim($_GET['search'] ?? '');

// Fetch Members
$sql_m = "SELECT * FROM tbl_members";
$params_m = []; $types_m = '';
if (!empty($search_query)) {
    $sql_m .= " WHERE full_name LIKE ? OR member_uid LIKE ? OR department LIKE ?";
    $params_m = array_fill(0, 3, "%$search_query%"); $types_m = 'sss';
}
$sql_m .= " ORDER BY member_id DESC";
$stmt_m = $conn->prepare($sql_m);
if(!empty($params_m)) $stmt_m->bind_param($types_m, ...$params_m);
$stmt_m->execute();
$members_result = $stmt_m->get_result();

// Fetch Admins (Only if Super Admin)
$admins_result = false;
if ($is_logged_in_super_admin) {
    $sql_a = "SELECT a.*, l.library_name 
              FROM tbl_admin a 
              LEFT JOIN tbl_libraries l ON a.library_id = l.library_id";
    $params_a = []; $types_a = '';
    if (!empty($search_query)) {
        $sql_a .= " WHERE a.full_name LIKE ? OR a.username LIKE ? OR l.library_name LIKE ?";
        $params_a = array_fill(0, 3, "%$search_query%"); $types_a = 'sss';
    }
    $sql_a .= " ORDER BY a.admin_id DESC";
    $stmt_a = $conn->prepare($sql_a);
    if(!empty($params_a)) $stmt_a->bind_param($types_a, ...$params_a);
    $stmt_a->execute();
    $admins_result = $stmt_a->get_result();
}

admin_header('User Management');
?>

<style>
    body, .glass-card, .form-control, .glass-table td { color: #111827; }
    @keyframes modalSlideUp { from { transform: translateY(50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    .glass-card {
        background: rgba(255, 255, 255, 0.7); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.7); box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.1);
        border-radius: 20px; padding: 30px; margin-bottom: 30px; animation: fadeIn 0.5s ease-out;
    }
    .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
    .card-header h2 { margin: 0; color: #000; font-size: 1.5rem; display: flex; align-items: center; gap: 10px; }

    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }
    .form-group { margin-bottom: 10px; }
    .form-group label { display: block; font-size: 0.85rem; font-weight: 700; color: #111827; margin-bottom: 5px; }
    .form-control { width: 85%; padding: 12px; border: 1px solid #d1d5db; background: rgba(255,255,255,0.85); border-radius: 10px; color: #111827; transition: 0.3s; }
    .form-control:focus { background: #fff; border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); outline: none; }

    .btn-main { background: linear-gradient(135deg, #4f46e5, #3730a3); color: white; border: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; transition: transform 0.2s; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); }
    .btn-main:hover { transform: translateY(-2px); }
    
    .btn-bulk { background: linear-gradient(135deg, #059669, #047857); color: white; padding: 10px 20px; border-radius: 10px; border: none; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 10px rgba(5, 150, 105, 0.3); }
    .btn-bulk:hover { transform: translateY(-2px); }

    .action-btn { width: 32px; height: 32px; border-radius: 8px; border: none; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; margin-right: 5px; color: white; transition: 0.2s; }
    .btn-edit { background: #1d4ed8; }
    .btn-pass { background: #d97706; }
    .btn-del { background: #b91c1c; }
    .action-btn:hover { transform: translateY(-2px); opacity: 0.9; }

    .tabs-container { display: flex; gap: 10px; margin-bottom: 20px; background: rgba(255,255,255,0.4); padding: 5px; border-radius: 30px; width: fit-content; border: 1px solid rgba(255,255,255,0.7); }
    .tab-btn { padding: 10px 25px; border-radius: 25px; border: none; background: transparent; color: #4b5563; font-weight: 700; cursor: pointer; transition: 0.3s; }
    .tab-btn.active { background: linear-gradient(135deg, #4f46e5, #3730a3); color: white; }
    .tab-pane { display: none; animation: fadeIn 0.3s; } .tab-pane.active { display: block; }

    .table-container { overflow-x: auto; border-radius: 12px; max-height: 500px; overflow-y: auto; }
    .glass-table { width: 100%; border-collapse: collapse; background: rgba(255, 255, 255, 0.5); }
    .glass-table th { background: rgba(79, 70, 229, 0.1); color: #111827; font-weight: 700; padding: 15px; text-align: left; white-space: nowrap; position: sticky; top: 0; z-index: 10; backdrop-filter: blur(5px); }
    .glass-table td { padding: 15px; border-bottom: 1px solid rgba(0,0,0,0.05); color: #111827; font-size: 0.95rem; vertical-align: middle; }
    .glass-table tr:hover { background: rgba(255,255,255,0.7); }
    
    .badge { padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; display:inline-block; }
    .badge-active { background: #d1fae5; color: #065f46; }
    .badge-inactive { background: #fee2e2; color: #991b1b; }
    .badge-super { background:#f3e8ff; color:#6b21a8; }
    .badge-lib { background:#e0f2fe; color:#0369a1; }

    /* Modal Styling */
    .modal { display: none; position: fixed; z-index: 3000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(17, 24, 39, 0.7); backdrop-filter: blur(8px); justify-content: center; align-items: center; }
    .modal-dialog { background: #fff; border-radius: 24px; width: 90%; max-width: 600px; animation: modalSlideUp 0.4s; overflow: hidden; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.25); }
    
    /* Specific sizing for bulk preview modal */
    #bulkPreviewModal .modal-dialog { 
        max-width: 900px; 
        height: 90vh; 
        display: flex; 
        flex-direction: column; 
    }

    .modal-header { background: #f8fafc; padding: 20px 30px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .modal-body { padding: 30px; }
    .modal-footer { padding: 20px 30px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; justify-content: flex-end; gap: 12px; }
    .btn-close-modal { background: transparent; border: none; font-size: 1.5rem; color: #6b7280; cursor: pointer; }
    .btn-cancel { background: #e5e7eb; color: #4b5563; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-save { background: #1d4ed8; color: white; padding: 10px 20px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; }

    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 600; }
    .alert-success { background: #d1fae5; color: #065f46; }
    .alert-danger { background: #fee2e2; color: #991b1b; }

    /* LOADING OVERLAY STYLES */
    #loadingOverlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9); 
        backdrop-filter: blur(5px);
        z-index: 99999; 
        display: none; 
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }

    .loading-content {
        background: white;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.2);
        text-align: center;
        border: 1px solid #e5e7eb;
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #e0e7ff;
        border-top: 5px solid #4f46e5;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px auto;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 1.2rem;
        font-weight: 700;
        color: #111827;
        margin: 0;
    }
</style>

<div id="loadingOverlay">
    <div class="loading-content">
        <div class="spinner"></div>
        <h3 class="loading-text">Processing...</h3>
        <p style="color:#666;">Please wait while we update records and send emails.</p>
    </div>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-user-plus" style="color: #4f46e5;"></i> Add User</h2>
        <button onclick="document.getElementById('bulkUploadModal').style.display='flex'" class="btn-bulk">
            <i class="fas fa-file-upload"></i> Bulk Create (Members)
        </button>
    </div>
    
    <?php if ($message): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> <?php endif; ?>
    <?php if ($error): ?> <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> <?php endif; ?>
    
    <form method="POST" class="form-grid" id="add_user_form">
        <input type="hidden" name="action" value="add_user">
        
        <div>
            <div class="form-group"><label>Account Type</label></div>
            <select id="account_type" name="account_type" class="form-control" onchange="toggleAccountFields()">
                <option value="member">Member (Student/Faculty)</option>
                <?php if ($is_logged_in_super_admin): ?>
                <option value="admin">Admin / Librarian</option>
                <?php endif; ?>
            </select>
        </div>
        
        <div>
            <div class="form-group"><label>Full Name</label></div>
            <input type="text" name="full_name" class="form-control" required>
        </div>
        
        <div>
            <div class="form-group"><label>User ID / Username</label></div>
            <input type="text" name="member_uid" class="form-control" required placeholder="e.g. 1001 or username">
        </div>

        <div id="member_fields" style="display:contents;">
            <div>
                <div class="form-group"><label>Email</label></div>
                <input type="email" name="email" class="form-control">
            </div>
            <div>
                <div class="form-group"><label>Department</label></div>
                <input type="text" id="dept_input" name="department" class="form-control" required>
            </div>
        </div>

        <?php if ($is_logged_in_super_admin): ?>
        <div id="admin_fields" style="display:none; grid-column: 1/-1;">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px;">
                <div>
                    <div class="form-group"><label>Assign Library</label></div>
                    <select name="library_id" id="add_lib_select" class="form-control" onchange="handleLibChange()">
                        <option value="0">All Libraries</option>
                        <?php foreach($libraries as $lib): ?>
                            <option value="<?php echo $lib['library_id']; ?>"><?php echo htmlspecialchars($lib['library_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <label style="display:flex; align-items:center; gap:10px; background:rgba(79, 70, 229, 0.1); padding:12px; border-radius:8px; width: 100%;">
                        <input type="checkbox" name="is_super_admin" id="add_super_check" value="1" style="accent-color:#4f46e5; width: 20px; height: 20px;" onchange="handleSuperCheck()">
                        <span style="font-size:0.9rem; color:#3730a3; font-weight:700;">Grant Super Admin Privileges</span>
                    </label>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div id="admin_fields" style="display:none;"></div>
        <?php endif; ?>

        <div style="grid-column: 1/-1;">
            <div class="form-group"><label>Password</label></div>
            <input type="text" name="password" value="password" class="form-control">
        </div>

        <div style="grid-column: 1/-1;">
            <button type="submit" class="btn-main">Create Account</button>
        </div>
    </form>
</div>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-users" style="color: #4f46e5;"></i> Manage Users</h2>
        <form method="GET" style="display:flex; gap:10px;">
            <input type="search" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>" style="width:200px;">
            <button type="submit" class="btn-main" style="width:auto; padding: 12px;"><i class="fas fa-search"></i></button>
        </form>
    </div>

    <div class="tabs-container">
        <button class="tab-btn active" onclick="openTab(event, 'tabMembers')">Members</button>
        <?php if ($is_logged_in_super_admin): ?>
        <button class="tab-btn" onclick="openTab(event, 'tabAdmins')">Admins / Librarians</button>
        <?php endif; ?>
    </div>

    <div id="tabMembers" class="tab-pane active">
        <div class="table-container">
            <table class="glass-table">
                <thead>
                    <tr><th>Name</th><th>ID</th><th>Dept</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php while ($m = $members_result->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight:700;"><?php echo htmlspecialchars($m['full_name']); ?></td>
                        <td><span style="font-family:monospace; background:rgba(0,0,0,0.05); color:#111827; padding:2px 6px; border-radius:4px;"><?php echo htmlspecialchars($m['member_uid']); ?></span></td>
                        <td><?php echo htmlspecialchars($m['department']); ?></td>
                        <td><span class="badge <?php echo $m['status']=='Active'?'badge-active':'badge-inactive'; ?>"><?php echo $m['status']; ?></span></td>
                        <td>
                            <button class="action-btn btn-edit" onclick='openEditModal(<?php echo json_encode($m); ?>)'><i class="fas fa-edit"></i></button>
                            <button class="action-btn btn-pass" onclick='openPassModal(<?php echo json_encode($m); ?>, "member")'><i class="fas fa-key"></i></button>
                            <button class="action-btn btn-del" onclick='openDeleteModal(<?php echo json_encode($m); ?>)'><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($is_logged_in_super_admin): ?>
    <div id="tabAdmins" class="tab-pane">
        <div class="table-container">
            <table class="glass-table">
                <thead><tr><th>Name</th><th>Username</th><th>Assigned Library</th><th>Role</th><th>Action</th></tr></thead>
                <tbody>
                    <?php while ($a = $admins_result->fetch_assoc()): ?>
                    <tr>
                        <td style="font-weight:700;"><?php echo htmlspecialchars($a['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($a['username']); ?></td>
                        <td><?php echo htmlspecialchars($a['library_name'] ?? 'All'); ?></td>
                        <td><?php echo $a['is_super_admin'] ? '<span class="badge badge-super">Super Admin</span>' : '<span class="badge badge-lib">Librarian</span>'; ?></td>
                        <td>
                            <?php if($is_logged_in_super_admin): ?>
                                <button class="action-btn btn-edit" onclick='openEditAdminModal(<?php echo json_encode($a); ?>)' title="Edit Details"><i class="fas fa-edit"></i></button>
                                <button class="action-btn btn-pass" onclick='openPassModal(<?php echo json_encode($a); ?>, "admin")' title="Reset Password"><i class="fas fa-key"></i></button>
                                <?php if($a['admin_id'] != $_SESSION['admin_id']): ?>
                                <button class="action-btn btn-del" onclick='openDeleteAdminModal(<?php echo $a['admin_id']; ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af; font-size:0.8rem;">Restricted</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<div id="bulkUploadModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-file-csv" style="color:#059669;"></i> Bulk Member Upload</h3>
            <button class="btn-close-modal" onclick="closeModal('bulkUploadModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="bulk_form">
            <div class="modal-body">
                <input type="hidden" name="action" value="preview_bulk">
                <div class="alert alert-success" style="background:#f0fdf4; border:1px solid #a7f3d0; color:#065f46; display:block;">
                    <strong><i class="fas fa-info-circle"></i> CSV Format:</strong><br>
                    Columns required: <code style="background:rgba(0,0,0,0.05); padding:2px 4px;">Type, Name, ID, Email, Dept</code>
                </div>
                <div class="form-group">
                    <label>Select File (CSV)</label>
                    <input type="file" name="bulk_file" class="form-control" accept=".csv" required style="padding:10px 12px; width:95%; background: #fff;">
                </div>
                <div class="form-group">
                    <label>Default Password</label>
                    <input type="text" name="default_password" class="form-control" value="password" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('bulkUploadModal')">Cancel</button>
                <button type="submit" class="btn-save" style="background:#059669;">Preview</button>
            </div>
        </form>
    </div>
</div>

<?php if ($show_bulk_preview && !empty($bulk_data)): ?>
<div id="bulkPreviewModal" class="modal" style="display: flex;">
    <div class="modal-dialog" style="max-width: 900px;">
        <div class="modal-header">
            <h3><i class="fas fa-list-check" style="color:#059669;"></i> Preview & Edit Import</h3>
            <button class="btn-close-modal" onclick="window.location.href='members.php'">&times;</button>
        </div>
        <form method="POST" action="members.php" id="bulk_confirm_form">
            <div class="modal-body" style="max-height: 60vh; overflow-y: auto; padding: 0;">
                <input type="hidden" name="action" value="confirm_bulk">
                
                <table class="glass-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Type</th>
                            <th>Name</th>
                            <th style="width: 120px;">UID</th>
                            <th>Email</th>
                            <th style="width: 150px;">Dept</th>
                            <th style="width: 50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($bulk_data as $index => $row): ?>
                        <tr id="row_<?php echo $index; ?>">
                            <td>
                                <select name="bulk_data[<?php echo $index; ?>][type]" class="form-control" style="padding: 8px; font-size: 0.9rem;">
                                    <option value="member" <?php echo (strtolower($row['type']) == 'member') ? 'selected' : ''; ?>>Member</option>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="bulk_data[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($row['name']); ?>" class="form-control" required style="padding: 8px;">
                            </td>
                            <td>
                                <input type="text" name="bulk_data[<?php echo $index; ?>][uid]" value="<?php echo htmlspecialchars($row['uid']); ?>" class="form-control" required style="padding: 8px; font-family: monospace;">
                            </td>
                            <td>
                                <input type="email" name="bulk_data[<?php echo $index; ?>][email]" value="<?php echo htmlspecialchars($row['email']); ?>" class="form-control" style="padding: 8px;">
                            </td>
                            <td>
                                <input type="text" name="bulk_data[<?php echo $index; ?>][dept]" value="<?php echo htmlspecialchars($row['dept']); ?>" class="form-control" required style="padding: 8px;">
                            </td>
                            <td>
                                <input type="hidden" name="bulk_data[<?php echo $index; ?>][default_pass]" value="<?php echo htmlspecialchars($row['default_pass']); ?>">
                                <button type="button" class="action-btn btn-del" onclick="document.getElementById('row_<?php echo $index; ?>').remove()"><i class="fas fa-times"></i></button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <a href="members.php" class="btn-cancel">Cancel</a>
                <button type="submit" class="btn-save" style="background:#059669;">Confirm & Create</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="editModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-header"><h3><i class="fas fa-user-edit" style="color:#1d4ed8;"></i> Edit Member</h3><button class="btn-close-modal" onclick="closeModal('editModal')">&times;</button></div>
        <form method="POST" id="edit_member_form"><div class="modal-body"><input type="hidden" name="action" value="edit_member"><input type="hidden" id="edit_member_id" name="member_id"><div class="form-grid"><div class="form-group"><label>Member ID</label><input type="text" id="edit_uid" class="form-control" disabled style="background:#f3f4f6; color:#6b7280;"></div><div class="form-group"><label>Full Name</label><input type="text" id="edit_name" name="full_name" class="form-control" required></div><div class="form-group"><label>Email</label><input type="email" id="edit_email" name="email" class="form-control"></div><div class="form-group"><label>Department</label><input type="text" id="edit_dept" name="department" class="form-control" required></div></div><div style="margin-top:15px;"><label style="display:flex; align-items:center; gap:10px; font-weight:700;">Active Account <input type="checkbox" id="edit_status_toggle" name="status" value="Active" style="accent-color:#059669; width:18px; height:18px;"></label></div></div><div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancel</button><button type="submit" class="btn-save">Save Changes</button></div></form>
    </div>
</div>

<div id="passModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-key" style="color:#d97706;"></i> Reset Password</h3>
            <button class="btn-close-modal" onclick="closeModal('passModal')">&times;</button>
        </div>
        <form method="POST" id="pass_form">

            <div class="modal-body">
                <div id="pass_details_container" class="user-details-box" style="background: rgba(243, 244, 246, 0.8); border-radius: 10px; padding: 15px; margin-bottom: 20px; border: 1px solid #e5e7eb;"></div>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" id="pass_target_id" name="target_id">
                <input type="hidden" id="pass_target_type" name="target_type">
                <div class="form-group">
                    <label>New Password</label>
                    <input type="text" name="new_password" class="form-control" required placeholder="Enter new password">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('passModal')">Cancel</button>
                <button type="submit" class="btn-save" style="background:#d97706;">Update Password</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-trash" style="color:#b91c1c;"></i> Confirm Deletion</h3>
            <button class="btn-close-modal" onclick="closeModal('deleteModal')">&times;</button>
        </div>
        <form method="POST" id="delete_member_form">

            <input type="hidden" name="action" value="delete_member">
            <input type="hidden" id="delete_member_id" name="member_id">
            
            <div class="modal-body">
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 15px; margin-bottom: 20px;">
                    <div style="display: flex; justify-content: center; margin-bottom: 15px;">
                        <div style="width: 60px; height: 60px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #b91c1c; font-size: 1.5rem;">
                            <i class="fas fa-user-times"></i>
                        </div>
                    </div>
                    <h4 style="text-align: center; margin: 0 0 10px 0; color: #991b1b;">Delete Member Account?</h4>
                    <p style="text-align: center; font-size: 0.9rem; color: #7f1d1d; margin: 0;">This action is permanent and cannot be undone.</p>
                </div>

                <div style="background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; font-size: 0.95rem;">
                        <div style="color: #64748b; font-weight: 600;">Full Name:</div>
                        <div id="del_name" style="font-weight: 700; color: #1e293b;"></div>
                        
                        <div style="color: #64748b; font-weight: 600;">Member ID:</div>
                        <div id="del_uid" style="font-family: monospace; color: #4f46e5;"></div>
                        
                        <div style="color: #64748b; font-weight: 600;">Department:</div>
                        <div id="del_dept" style="color: #334155;"></div>
                        
                        <div style="color: #64748b; font-weight: 600;">Email:</div>
                        <div id="del_email" style="color: #334155;"></div>
                        
                        <div style="color: #64748b; font-weight: 600;">Status:</div>
                        <div id="del_status"></div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('deleteModal')">Cancel</button>
                <button type="submit" class="btn-save" style="background:#b91c1c;">Delete</button>
            </div>
        </form>
    </div>
</div>

<div id="editAdminModal" class="modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-user-cog" style="color:#1d4ed8;"></i> Edit Admin / Librarian</h3>
            <button class="btn-close-modal" onclick="closeModal('editAdminModal')">&times;</button>
        </div>
        <form method="POST" id="edit_admin_form">
            <div class="modal-body">
                <input type="hidden" name="action" value="edit_admin">
                <input type="hidden" id="edit_admin_id" name="admin_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" id="edit_admin_name" name="full_name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" id="edit_admin_username" name="username" class="form-control" required>
                    </div>
                </div>
                <div class="form-group" style="margin-top:15px;">
                    <label>Assigned Library</label>
                    <select name="library_id" id="edit_admin_library" class="form-control">
                        <option value="0">All Libraries</option>
                        <?php foreach($libraries as $lib): ?>
                            <option value="<?php echo $lib['library_id']; ?>"><?php echo htmlspecialchars($lib['library_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="margin-top:15px;">
                    <label style="display:flex; align-items:center; gap:10px; font-weight:700; color:#111827;">
                        <input type="checkbox" id="edit_admin_super" name="is_super_admin" value="1" style="accent-color:#4f46e5; width:18px; height:18px;">
                        Grant Super Admin Privileges
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal('editAdminModal')">Cancel</button>
                <button type="submit" class="btn-save">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="deleteAdminForm"><input type="hidden" name="action" value="delete_admin"><input type="hidden" name="admin_id" id="delete_admin_id_input"></form>

<script>
// --- Javascript Functions ---

// Loading Overlay Logic
function showLoading() {
    document.getElementById('loadingOverlay').style.display = 'flex';
}

// Attach showLoading to all relevant forms
document.addEventListener('DOMContentLoaded', function() {
    const forms = ['add_user_form', 'edit_member_form', 'pass_form', 'delete_member_form', 'edit_admin_form', 'bulk_form', 'bulk_confirm_form'];
    forms.forEach(function(formId) {
        const form = document.getElementById(formId);
        if (form) {
            form.addEventListener('submit', function() {
                showLoading();
            });
        }
    });
});

function openTab(evt, tabName) {
    document.querySelectorAll('.tab-pane').forEach(x => x.style.display = "none");
    document.querySelectorAll('.tab-btn').forEach(x => x.classList.remove("active"));
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.classList.add("active");
}

function toggleAccountFields() {
    const type = document.getElementById('account_type').value;
    document.getElementById('member_fields').style.display = (type === 'member') ? 'contents' : 'none';
    const adminFields = document.getElementById('admin_fields');
    if (adminFields) adminFields.style.display = (type === 'admin') ? 'block' : 'none';
    const deptInput = document.getElementById('dept_input');
    if (deptInput) deptInput.required = (type === 'member');
}

function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// Member Edit
function openEditModal(data) {
    document.getElementById('edit_member_id').value = data.member_id;
    document.getElementById('edit_uid').value = data.member_uid;
    document.getElementById('edit_name').value = data.full_name;
    document.getElementById('edit_email').value = data.email;
    document.getElementById('edit_dept').value = data.department;
    document.getElementById('edit_status_toggle').checked = (data.status === 'Active');
    document.getElementById('editModal').style.display = 'flex';
}

// Admin Edit
function openEditAdminModal(data) {
    <?php if (!$is_logged_in_super_admin): ?>
        alert('Access Denied. Only Super Admins can edit other admin accounts.');
        return;
    <?php endif; ?>
    document.getElementById('edit_admin_id').value = data.admin_id;
    document.getElementById('edit_admin_name').value = data.full_name;
    document.getElementById('edit_admin_username').value = data.username;
    document.getElementById('edit_admin_super').checked = (data.is_super_admin == 1);
    const libSelect = document.getElementById('edit_admin_library');
    libSelect.value = data.library_id ? data.library_id : 0;
    document.getElementById('editAdminModal').style.display = 'flex';
}

// Password Reset
function openPassModal(data, type) {
    if (type === 'admin' && !<?php echo $is_logged_in_super_admin ? 'true' : 'false'; ?>) {
        alert('Access Denied. You cannot reset Admin passwords.');
        return;
    }
    
    let id = (type === 'member') ? data.member_id : data.admin_id;
    document.getElementById('pass_target_id').value = id;
    document.getElementById('pass_target_type').value = type;

    const container = document.getElementById('pass_details_container');
    let html = '';

    if (type === 'member') {
        html += `<div class="user-detail-row" style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#6b7280;font-weight:600;">Name:</span> <span style="font-weight:700;">${data.full_name}</span></div>`;
        html += `<div class="user-detail-row" style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#6b7280;font-weight:600;">ID:</span> <span style="font-weight:700;">${data.member_uid}</span></div>`;
        html += `<div class="user-detail-row" style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#6b7280;font-weight:600;">Dept:</span> <span style="font-weight:700;">${data.department}</span></div>`;
    } else if (type === 'admin') {
        const role = (data.is_super_admin == 1) ? 'Super Admin' : 'Librarian';
        html += `<div class="user-detail-row" style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#6b7280;font-weight:600;">Name:</span> <span style="font-weight:700;">${data.full_name}</span></div>`;
        html += `<div class="user-detail-row" style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#6b7280;font-weight:600;">Username:</span> <span style="font-weight:700;">${data.username}</span></div>`;
        html += `<div class="user-detail-row" style="display:flex;justify-content:space-between;margin-bottom:5px;"><span style="color:#6b7280;font-weight:600;">Role:</span> <span style="font-weight:700;">${role}</span></div>`;
    }

    container.innerHTML = html;
    document.getElementById('passModal').style.display = 'flex';
}

// Updated Member Delete Logic
function openDeleteModal(data) {
    document.getElementById('delete_member_id').value = data.member_id;
    
    // Populate Fields
    document.getElementById('del_name').textContent = data.full_name;
    document.getElementById('del_uid').textContent = data.member_uid;
    document.getElementById('del_dept').textContent = data.department;
    document.getElementById('del_email').textContent = data.email ? data.email : 'N/A';
    
    // Status Style
    const statusEl = document.getElementById('del_status');
    statusEl.textContent = data.status;
    if (data.status === 'Active') {
        statusEl.style.color = '#059669';
        statusEl.style.fontWeight = '700';
    } else {
        statusEl.style.color = '#dc2626';
        statusEl.style.fontWeight = '700';
    }

    document.getElementById('deleteModal').style.display = 'flex';
}

// Admin Delete
function openDeleteAdminModal(id) {
    if (!<?php echo $is_logged_in_super_admin ? 'true' : 'false'; ?>) {
        alert('Access Denied. Only Super Admins can delete admin accounts.');
        return;
    }
    if(confirm('Are you sure you want to delete this Admin?')) {
        document.getElementById('delete_admin_id_input').value = id;
        document.getElementById('deleteAdminForm').submit();
    }
}

// --- Logic for Super Admin / Library Interdependency ---

function handleSuperCheck() {
    const superCheck = document.getElementById('add_super_check');
    const libSelect = document.getElementById('add_lib_select');

    if (superCheck.checked) {
        // If Super Admin is checked:
        // 1. Force Library to "All Libraries" (0)
        libSelect.value = '0';
        // 2. Disable interaction with the dropdown (visually)
        libSelect.style.pointerEvents = 'none';
        libSelect.style.backgroundColor = '#e5e7eb'; // Grey out visually
        // We avoid libSelect.disabled = true because disabled fields aren't sent in POST
    } else {
        // Re-enable interaction
        libSelect.style.pointerEvents = 'auto';
        libSelect.style.backgroundColor = '#fff';
    }
}

function handleLibChange() {
    const superCheck = document.getElementById('add_super_check');
    const libSelect = document.getElementById('add_lib_select');

    if (libSelect.value !== '0') {
        // If a specific library is selected:
        // 1. Uncheck Super Admin
        superCheck.checked = false;
        // 2. Disable the Super Admin option (Super Admins must have global access)
        superCheck.disabled = true;
        superCheck.parentElement.style.opacity = '0.5'; // Visual cue
    } else {
        // If "All Libraries" is selected, give the option to be Super Admin
        superCheck.disabled = false;
        superCheck.parentElement.style.opacity = '1';
    }
}

window.onclick = function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    if (!new URLSearchParams(window.location.search).get('search')) {
        document.querySelector('.tabs-container .tab-btn').click(); 
    }
    toggleAccountFields();
});
</script>

<?php 
$members_result->data_seek(0);
admin_footer(); 
close_db_connection($conn); 
?>