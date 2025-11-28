<?php
require_once 'includes/functions.php';
global $conn;

// If already logged in, redirect to dashboard
if (is_member_logged_in()) {
    redirect('dashboard.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $member_uid = $_POST['member_uid'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($member_uid) || empty($password)) {
        $error = "Please enter both Member ID and password.";
    } else {
        $stmt = $conn->prepare("SELECT member_id, password, full_name, status FROM tbl_members WHERE member_uid = ?");
        $stmt->bind_param("s", $member_uid);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $member = $result->fetch_assoc();
            
            // **SECURITY NOTE**: Password stored in plain text as requested.
            if ($password === $member['password']) { // Note: Ideally use password_verify($password, $member['password'])
                if ($member['status'] === 'Active') {
                    // FIX: Regenerate session ID to prevent fixation
                    session_regenerate_id(true); 
                    $_SESSION['member_id'] = $member['member_id'];
                    $_SESSION['member_uid'] = $member_uid;
                    $_SESSION['member_full_name'] = $member['full_name'];
                    redirect('index.php');
                } else {
                    $error = "Account is inactive. Please contact the Librarian.";
                }
            } else {
                $error = "Incorrect ID or Password.";
            }
        } else {
            $error = "Incorrect ID or Password.";
        }
    }
}

// Fetch Branding Details
$inst_name = htmlspecialchars(get_setting($conn, 'institution_name') ?? 'Institution');
$lib_name = htmlspecialchars(get_setting($conn, 'library_name') ?? 'Library LMS');
$logo_path_raw = get_setting($conn, 'institution_logo');
$logo_path = (!empty($logo_path_raw) && file_exists($logo_path_raw)) ? $logo_path_raw : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - LMS Portal</title>
    
    <link rel="icon" type="image/png" href="<?php echo $logo_path; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-blue: #4361ee;
            --secondary-blue: #3f37c9;
            --accent-cyan: #4cc9f0;
            --bg-light: #f8f9fa;
            --text-dark: #2b2d42;
            --text-light: #8d99ae;
            --white: #ffffff;
            --error: #ef233c;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-light);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            position: relative;
            color: var(--text-dark);
        }

        /* Background Animation Canvas */
        #edu-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            background: radial-gradient(circle at center, #ffffff 0%, #eef2f3 100%);
        }

        /* Main Card */
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 900px;
            max-width: 95%;
            background: rgba(255, 255, 255, 0.85);
            border-radius: 24px;
            box-shadow: 0 20px 50px rgba(67, 97, 238, 0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            display: flex;
            overflow: hidden;
            animation: floatIn 0.8s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }

        @keyframes floatIn {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Left Side - Visual & Quote */
        .login-left {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-blue), var(--secondary-blue));
            padding: 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--white);
            text-align: center;
            position: relative;
        }

        /* Decorative circles in left panel */
        .login-left::before {
            content: '';
            position: absolute;
            top: -50px;
            left: -50px;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }
        .login-left::after {
            content: '';
            position: absolute;
            bottom: -30px;
            right: -30px;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .illustration-icon {
            font-size: 80px;
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.2);
            width: 140px;
            height: 140px;
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 50%;
            box-shadow: 0 8px 20px rgba(0,0,0,0.1);
            animation: pulse 3s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(255, 255, 255, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0); }
        }

        .login-left h2 {
            font-family: 'Nunito', sans-serif;
            font-weight: 700;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .quote {
            font-style: italic;
            opacity: 0.9;
            font-size: 14px;
            line-height: 1.6;
            margin-top: 20px;
        }

        /* Right Side - Form */
        .login-right {
            flex: 1.2;
            padding: 50px;
            background: var(--white);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .form-header {
            margin-bottom: 30px;
        }

        .form-header h3 {
            color: var(--text-dark);
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .form-header p {
            color: var(--text-light);
            font-size: 14px;
        }

        /* Form Inputs */
        .form-group {
            position: relative;
            margin-bottom: 25px;
        }

        .form-group input {
            width: 100%;
            padding: 16px 16px 16px 50px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-family: 'Poppins', sans-serif;
            font-size: 15px;
            outline: none;
            transition: all 0.3s ease;
            color: var(--text-dark);
            background: #f8faff;
        }

        .form-group input:focus {
            border-color: var(--primary-blue);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(67, 97, 238, 0.1);
        }

        .form-group i.field-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            transition: 0.3s;
            font-size: 18px;
        }

        .form-group input:focus ~ i.field-icon {
            color: var(--primary-blue);
        }

        .form-group i.toggle-pass {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            cursor: pointer;
        }
        .form-group i.toggle-pass:hover {
            color: var(--primary-blue);
        }

        .form-label {
            position: absolute;
            left: 50px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0aec0;
            font-size: 15px;
            pointer-events: none;
            transition: 0.3s;
            background: transparent;
        }

        .form-group input:focus ~ .form-label,
        .form-group input:not(:placeholder-shown) ~ .form-label {
            top: -10px;
            left: 15px;
            font-size: 12px;
            color: var(--primary-blue);
            background: #fff;
            padding: 0 5px;
            font-weight: 600;
        }

        /* Button */
        .btn-login {
            width: 100%;
            padding: 16px;
            background: var(--primary-blue);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 20px rgba(67, 97, 238, 0.2);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn-login:hover {
            background: var(--secondary-blue);
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(67, 97, 238, 0.3);
        }

        /* Utilities */
        .error-msg {
            background: #fff5f5;
            color: var(--error);
            padding: 12px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            border-left: 4px solid var(--error);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bottom-links {
            margin-top: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }

        .bottom-links a {
            text-decoration: none;
            color: var(--text-light);
            transition: 0.3s;
        }
        .bottom-links a:hover {
            color: var(--primary-blue);
            text-decoration: underline;
        }

        /* Mobile Responsive */
        @media (max-width: 800px) {
            .login-wrapper {
                flex-direction: column;
                width: 90%;
                height: auto;
            }
            .login-left {
                padding: 30px;
                min-height: 200px;
            }
            .illustration-icon { width: 80px; height: 80px; font-size: 40px; }
            .quote { display: none; }
            .login-right { padding: 30px; }
        }
    </style>
</head>
<body>

    <canvas id="edu-canvas"></canvas>

    <div class="login-wrapper">
        
        <div class="login-left">
            <div class="illustration-icon" style="background: rgba(255, 255, 255, 0.2); box-shadow: none;">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo" style="height: 100px; width: 100px; object-fit: contain; border-radius: 50%; padding: 10px; background: rgba(255,255,255,0.7);">
                <?php else: ?>
                    <i class="fas fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <h2><?php echo $inst_name; ?></h2>
            <p><?php echo $lib_name; ?></p>
            <div class="quote">
                "The more that you read, the more things you will know. The more that you learn, the more places you'll go."
            </div>
        </div>

        <div class="login-right">
            <div class="form-header">
                <h3>Welcome Back!</h3>
                <p>Please login to access the library catalog.</p>
            </div>

            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <input type="text" id="member_uid" name="member_uid" placeholder=" " required value="<?php echo isset($_POST['member_uid']) ? htmlspecialchars($_POST['member_uid']) : ''; ?>">
                    <label class="form-label">Student / Faculty ID</label>
                    <i class="field-icon fas fa-id-card-alt"></i>
                </div>

                <div class="form-group">
                    <input type="password" id="password" name="password" placeholder=" " required>
                    <label class="form-label">Password</label>
                    <i class="field-icon fas fa-lock"></i>
                    <i class="toggle-pass fas fa-eye" onclick="togglePassword()"></i>
                </div>

                <button type="submit" class="btn-login">
                    Login <i class="fas fa-arrow-right"></i>
                </button>

                <div class="bottom-links">
                    <a href="forgot_password.php">Forgot Password?</a>
                    <a href="admin/login.php" style="color: var(--primary-blue); font-weight: 600;">Admin Login</a>
                </div>
            </form>
        </div>

    </div>

    <script>
        function togglePassword() {
            const passInput = document.getElementById('password');
            const icon = document.querySelector('.toggle-pass');
            if (passInput.type === 'password') {
                passInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        // ==========================================
        // CLEAN "KNOWLEDGE NETWORK" ANIMATION
        // ==========================================
        const canvas = document.getElementById("edu-canvas");
        const ctx = canvas.getContext("2d");
        let w, h, particles;

        function init() {
            resize();
            animationLoop();
        }

        function resize() {
            w = canvas.width = window.innerWidth;
            h = canvas.height = window.innerHeight;
            particles = [];
            let particleCount = (w * h) / 10000; // Adjust density
            for (let i = 0; i < particleCount; i++) {
                particles.push(new Particle());
            }
        }

        class Particle {
            constructor() {
                this.x = Math.random() * w;
                this.y = Math.random() * h;
                this.vx = (Math.random() - 0.5) * 0.5; // Slow, calm movement
                this.vy = (Math.random() - 0.5) * 0.5;
                this.size = Math.random() * 3 + 1;
            }
            update() {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x < 0 || this.x > w) this.vx *= -1;
                if (this.y < 0 || this.y > h) this.vy *= -1;
            }
            draw() {
                // Light Blue Dots
                ctx.fillStyle = "rgba(67, 97, 238, 0.3)"; 
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        function animationLoop() {
            ctx.clearRect(0, 0, w, h);
            
            // Draw Connections
            for (let a = 0; a < particles.length; a++) {
                let pA = particles[a];
                pA.update();
                pA.draw();
                
                // Connect nearby dots
                for (let b = a; b < particles.length; b++) {
                    let pB = particles[b];
                    let dx = pA.x - pB.x;
                    let dy = pA.y - pB.y;
                    let dist = Math.sqrt(dx*dx + dy*dy);

                    if (dist < 150) {
                        // Faint grey lines for "Structure" look
                        ctx.strokeStyle = `rgba(67, 97, 238, ${0.1 - dist/1500})`;
                        ctx.lineWidth = 0.5;
                        ctx.beginPath();
                        ctx.moveTo(pA.x, pA.y);
                        ctx.lineTo(pB.x, pB.y);
                        ctx.stroke();
                    }
                }
            }
            requestAnimationFrame(animationLoop);
        }

        window.addEventListener("resize", resize);
        init();
    </script>
</body>
</html>
<?php close_db_connection($conn); ?>