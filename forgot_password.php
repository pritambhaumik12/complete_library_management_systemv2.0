<?php
require_once 'includes/functions.php';
global $conn;

$reset_enabled = get_setting($conn, 'online_password_reset') == '1';
$step = $_SESSION['reset_step'] ?? 1;
$error = '';
$message = '';
$member_data = $_SESSION['reset_member_data'] ?? null;

// --- LOGIC HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // STEP 1: Find Member
    if (isset($_POST['find_member'])) {
        $uid = trim($_POST['member_uid']);
        $stmt = $conn->prepare("SELECT member_id, member_uid, full_name, email, department FROM tbl_members WHERE member_uid = ? AND status = 'Active'");
        $stmt->bind_param("s", $uid);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows > 0) {
            $member = $res->fetch_assoc();
            if (!empty($member['email'])) {
                $_SESSION['reset_member_data'] = $member;
                $_SESSION['reset_step'] = 2;
                $step = 2;
                $member_data = $member;
            } else {
                $error = "No email address linked to this account. Contact Admin.";
            }
        } else {
            $error = "Member ID not found or inactive.";
        }
    }
    
    // STEP 2: Send OTP
    elseif (isset($_POST['send_otp'])) {
        $otp = generate_random_alphanumeric(6);
        $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $member_id = $_SESSION['reset_member_data']['member_id'];
        
        $stmt = $conn->prepare("UPDATE tbl_members SET reset_otp = ?, reset_otp_expiry = ? WHERE member_id = ?");
        $stmt->bind_param("ssi", $otp, $expiry, $member_id);
        
        if ($stmt->execute()) {
            $to = $_SESSION['reset_member_data']['email'];
            $name = $_SESSION['reset_member_data']['full_name'];
            $subject = "Password Reset OTP";
            $body = "<h3>Password Reset Request</h3><p>Your OTP is: <strong style='font-size:1.2em; color:#4361ee;'>$otp</strong></p><p>This code expires in 15 minutes.</p>";
            
            if (send_system_email($to, $name, $subject, $body)) {
                $_SESSION['reset_step'] = 3;
                $step = 3;
                $message = "OTP sent to your registered email.";
            } else {
                $error = "Failed to send email. Please try again.";
            }
        }
    }
    
    // STEP 3: Verify OTP
    elseif (isset($_POST['verify_otp'])) {
        $input_otp = trim($_POST['otp']);
        $member_id = $_SESSION['reset_member_data']['member_id'];
        
        $stmt = $conn->prepare("SELECT member_id FROM tbl_members WHERE member_id = ? AND reset_otp = ? AND reset_otp_expiry > NOW()");
        $stmt->bind_param("is", $member_id, $input_otp);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            $_SESSION['reset_step'] = 4;
            $step = 4;
            $message = "OTP Verified.";
        } else {
            $error = "Invalid or Expired OTP.";
        }
    }
    
    // STEP 4: Reset Password
    elseif (isset($_POST['reset_pass'])) {
        $p1 = $_POST['new_password'];
        $p2 = $_POST['confirm_password'];
        $member_id = $_SESSION['reset_member_data']['member_id'];
        
        if ($p1 === $p2 && strlen($p1) >= 6) {
            // In a real app, hash this password! Using plain text as per your system's pattern
            $stmt = $conn->prepare("UPDATE tbl_members SET password = ?, reset_otp = NULL WHERE member_id = ?");
            $stmt->bind_param("si", $p1, $member_id);
            if ($stmt->execute()) {
                session_destroy(); // Clear reset session
                echo "<script>alert('Password Reset Successfully! Login now.'); window.location='login.php';</script>";
                exit;
            } else {
                $error = "Database error.";
            }
        } else {
            $error = "Passwords do not match or are too short (min 6 chars).";
        }
    }
    
    // Cancel/Back
    elseif (isset($_POST['cancel'])) {
        unset($_SESSION['reset_step']);
        unset($_SESSION['reset_member_data']);
        header("Location: forgot_password.php");
        exit;
    }
}

user_header('Forgot Password'); 

// Helper to mask email
function mask_email($email) {
    $parts = explode('@', $email);
    if(count($parts) != 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    $show = floor($len / 2);
    return substr($name, 0, $show) . str_repeat('*', $len - $show) . '@' . $parts[1];
}
?>
<style>
    /* Reusing login styles for consistency */
    .login-wrapper { display: flex; justify-content: center; padding: 50px 20px; }
    .glass-card { 
        background: rgba(255,255,255,0.85); backdrop-filter: blur(10px); 
        padding: 40px; border-radius: 20px; width: 100%; max-width: 500px; 
        box-shadow: 0 10px 40px rgba(0,0,0,0.1); 
    }
    .form-group { margin-bottom: 20px; position: relative; }
    .form-control { width: 90%; padding: 12px; border-radius: 8px; border: 1px solid #ccc; }
    .btn-primary { background: #4361ee; color: white; width: 100%; padding: 12px; border: none; border-radius: 8px; font-weight: 700; cursor: pointer; }
    .btn-link { background: none; border: none; color: #666; cursor: pointer; margin-top: 10px; text-decoration: underline; }
    .info-row { margin-bottom: 10px; font-size: 0.9rem; }
    .pass-wrapper { position: relative; }
    .toggle-pass { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #666; }
</style>

<div class="login-wrapper">
    <div class="glass-card">
        <h2 style="text-align: center; color: #2b2d42; margin-bottom: 20px;">Password Recovery</h2>
        
        <?php if ($error): ?>
            <div style="background:#fee2e2; color:#991b1b; padding:10px; border-radius:8px; margin-bottom:15px; text-align:center;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div style="background:#d1fae5; color:#065f46; padding:10px; border-radius:8px; margin-bottom:15px; text-align:center;">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if (!$reset_enabled): ?>
            <div style="text-align: center;">
                <i class="fas fa-lock" style="font-size: 3rem; color: #4361ee; margin-bottom: 15px;"></i>
                <p class="text-muted" style="line-height: 1.6; color: #4b5563;">
                    Online password reset is currently disabled. <br>
                    For security reasons, please contact the <strong>Librarian</strong> or <strong>IT Department</strong> with your ID card to reset your password.
                </p>
                <a href="login.php" class="btn-primary" style="display:inline-block; text-decoration:none; margin-top:15px;">Back to Login</a>
            </div>

        <?php else: ?>
            <?php if ($step == 1): ?>
                <form method="POST">
                    <p style="text-align: center; margin-bottom: 20px;">Enter your Member ID to begin.</p>
                    <div class="form-group">
                        <label>Member ID</label>
                        <input type="text" name="member_uid" class="form-control" placeholder="e.g. 1001" required>
                    </div>
                    <button type="submit" name="find_member" class="btn-primary">Find Account</button>
                </form>
            
            <?php elseif ($step == 2): ?>
                <form method="POST">
                    <div class="info-row"><strong>Name:</strong> <?php echo htmlspecialchars($member_data['full_name']); ?></div>
                    <div class="info-row"><strong>Dept:</strong> <?php echo htmlspecialchars($member_data['department']); ?></div>
                    <div class="info-row"><strong>Email:</strong> <?php echo mask_email($member_data['email']); ?></div>
                    
                    <p style="font-size: 0.85rem; color: #666; margin: 15px 0;">Click below to receive a 6-digit OTP on your registered email.</p>
                    
                    <button type="submit" name="send_otp" class="btn-primary">Send Reset OTP</button>
                    <button type="submit" name="cancel" class="btn-link" style="width:100%">Cancel</button>
                </form>

            <?php elseif ($step == 3): ?>
                <form method="POST">
                    <p style="text-align: center; margin-bottom: 20px;">Enter the 6-digit OTP sent to your email.</p>
                    <div class="form-group">
                        <input type="text" name="otp" class="form-control" placeholder="XXXXXX" style="text-align:center; letter-spacing: 5px; font-size: 1.2rem;" required>
                    </div>
                    <button type="submit" name="verify_otp" class="btn-primary">Verify OTP</button>
                    <button type="submit" name="cancel" class="btn-link" style="width:100%">Cancel</button>
                </form>

            <?php elseif ($step == 4): ?>
                <form method="POST">
                    <div style="background: #f8fafc; padding: 10px; border-radius: 8px; margin-bottom: 20px;">
                        <div class="info-row"><strong>Member:</strong> <?php echo htmlspecialchars($member_data['full_name']); ?></div>
                        <div class="info-row"><strong>ID:</strong> <?php echo htmlspecialchars($member_data['member_uid']); ?></div>
                    </div>

                    <div class="form-group">
                        <label>New Password</label>
                        <div class="pass-wrapper">
                            <input type="password" id="p1" name="new_password" class="form-control" required>
                            <i class="fas fa-eye toggle-pass" onclick="togglePass('p1')"></i>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <div class="pass-wrapper">
                            <input type="password" id="p2" name="confirm_password" class="form-control" required>
                            <i class="fas fa-eye toggle-pass" onclick="togglePass('p2')"></i>
                        </div>
                    </div>

                    <button type="submit" name="reset_pass" class="btn-primary">Reset Password</button>
                </form>
                <script>
                    function togglePass(id) {
                        var x = document.getElementById(id);
                        x.type = (x.type === "password") ? "text" : "password";
                    }
                </script>
            <?php endif; ?>

        <?php endif; ?>
    </div>
</div>

<?php user_footer(); close_db_connection($conn); ?>