<?php
require_once '../includes/functions.php';
global $conn;

// If already logged in, redirect to dashboard
if (is_admin_logged_in()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } else {
        $stmt = $conn->prepare("SELECT admin_id, password, full_name, is_super_admin FROM tbl_admin WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $admin = $result->fetch_assoc();
            
            // **SECURITY NOTE**: Password stored in plain text as requested.
            if ($password === $admin['password']) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin['admin_id'];
                $_SESSION['admin_full_name'] = $admin['full_name'];
                $_SESSION['is_super_admin'] = $admin['is_super_admin'];
                redirect('index.php');
            } else {
                $error = "Access Denied: Invalid credentials.";
            }
        } else {
            $error = "Access Denied: Invalid credentials.";
        }
    }
}

// Fetch Branding Details (using '../' prefix as this file is in the 'admin' directory)
$inst_name = htmlspecialchars(get_setting($conn, 'institution_name') ?? 'Institution');
$lib_name = htmlspecialchars(get_setting($conn, 'library_name') ?? 'Library LMS');
$logo_path_raw = get_setting($conn, 'institution_logo');
$logo_path = (!empty($logo_path_raw) && file_exists('../' . $logo_path_raw)) ? '../' . $logo_path_raw : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - LMS</title>
    
    <link rel="icon" type="image/png" href="<?php echo $logo_path; ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* --- SHARED STYLES (MATCHING MEMBER PORTAL) --- */
        :root {
            /* Using a slightly darker blue for Admin to distinguish it, 
               but keeping the same design language */
            --primary-blue: #2b2d42; /* Darker Blue/Grey */
            --secondary-blue: #1a1a2e;
            --accent-cyan: #4cc9f0;
            --bg-light: #f8f9fa;
            --text-dark: #2b2d42;
            --text-light: #8d99ae;
            --white: #ffffff;
            --error: #ef233c;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

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
        #admin-canvas {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
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
            box-shadow: 0 20px 50px rgba(43, 45, 66, 0.15);
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

        /* Left Side - Branding */
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

        /* Decorative circles */
        .login-left::before {
            content: ''; position: absolute; top: -50px; left: -50px;
            width: 200px; height: 200px; background: rgba(255, 255, 255, 0.05); border-radius: 50%;
        }
        .login-left::after {
            content: ''; position: absolute; bottom: -30px; right: -30px;
            width: 150px; height: 150px; background: rgba(255, 255, 255, 0.05); border-radius: 50%;
        }

        .illustration-icon {
            font-size: 80px;
            margin-bottom: 20px;
            background: rgba(255, 255, 255, 0.1);
            width: 140px; height: 140px;
            display: flex; justify-content: center; align-items: center;
            border-radius: 50%;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .login-left h2 {
            font-family: 'Nunito', sans-serif;
            font-weight: 700;
            font-size: 26px;
            margin-bottom: 10px;
        }

        .quote {
            font-style: italic; opacity: 0.8; font-size: 13px;
            line-height: 1.6; margin-top: 20px; max-width: 80%;
        }

        /* Right Side - Form */
        .login-right {
            flex: 1.2;
            padding: 50px;
            background: var(--white);
            display: flex; flex-direction: column; justify-content: center;
        }

        .form-header h3 {
            color: var(--text-dark); font-size: 24px; font-weight: 700; margin-bottom: 5px;
        }
        .form-header p { color: var(--text-light); font-size: 14px; margin-bottom: 30px; }

        /* Inputs */
        .form-group { position: relative; margin-bottom: 25px; }

        .form-group input {
            width: 100%; padding: 16px 16px 16px 50px;
            border: 2px solid #e2e8f0; border-radius: 12px;
            font-family: 'Poppins', sans-serif; font-size: 15px;
            outline: none; transition: all 0.3s ease;
            color: var(--text-dark); background: #f8faff;
        }

        .form-group input:focus {
            border-color: var(--primary-blue); background: #fff;
            box-shadow: 0 0 0 4px rgba(43, 45, 66, 0.1);
        }

        .field-icon {
            position: absolute; left: 18px; top: 50%; transform: translateY(-50%);
            color: #a0aec0; transition: 0.3s; font-size: 18px;
        }
        .form-group input:focus ~ .field-icon { color: var(--primary-blue); }

        .form-label {
            position: absolute; left: 50px; top: 50%; transform: translateY(-50%);
            color: #a0aec0; font-size: 15px; pointer-events: none;
            transition: 0.3s; background: transparent;
        }

        .form-group input:focus ~ .form-label,
        .form-group input:not(:placeholder-shown) ~ .form-label {
            top: -10px; left: 15px; font-size: 12px;
            color: var(--primary-blue); background: #fff;
            padding: 0 5px; font-weight: 600;
        }

        /* Button */
        .btn-login {
            width: 100%; padding: 16px;
            background: var(--primary-blue); color: white;
            border: none; border-radius: 12px;
            font-size: 16px; font-weight: 600; cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 10px 20px rgba(43, 45, 66, 0.2);
            display: flex; justify-content: center; align-items: center; gap: 10px;
        }
        .btn-login:hover {
            background: var(--secondary-blue);
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(43, 45, 66, 0.3);
        }

        /* Utilities */
        .error-msg {
            background: #fff5f5; color: var(--error); padding: 12px;
            border-radius: 8px; font-size: 14px; margin-bottom: 20px;
            border-left: 4px solid var(--error);
            display: flex; align-items: center; gap: 8px;
        }

        .bottom-link {
            margin-top: 25px; text-align: center; font-size: 14px;
        }
        .bottom-link a {
            color: var(--text-light); text-decoration: none; transition: 0.3s;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .bottom-link a:hover { color: var(--primary-blue); }

        /* Responsive */
        @media (max-width: 800px) {
            .login-wrapper { flex-direction: column; width: 90%; height: auto; }
            .login-left { padding: 30px; min-height: 180px; }
            .illustration-icon { width: 80px; height: 80px; font-size: 40px; margin-bottom: 10px; }
            .quote { display: none; }
            .login-right { padding: 30px; }
        }
    </style>
</head>
<body>

    <canvas id="admin-canvas"></canvas>

    <div class="login-wrapper">
        
        <div class="login-left">
            <div class="illustration-icon" style="background: rgba(255, 255, 255, 0.1); box-shadow: none;">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo" style="height: 100px; width: 100px; object-fit: contain; border-radius: 50%; padding: 10px; background: rgba(255,255,255,0.7);">
                <?php else: ?>
                    <i class="fas fa-shield-alt"></i>
                <?php endif; ?>
            </div>
            <h2><?php echo $inst_name; ?></h2>
            <p><?php echo $lib_name; ?></p>
            <div class="quote">
                "With great power comes great responsibility. Manage with care and integrity."
            </div>
        </div>

        <div class="login-right">
            <div class="form-header">
                <h3>Admin Access</h3>
                <p>Enter credentials to access the dashboard.</p>
            </div>

            <?php if ($error): ?>
                <div class="error-msg">
                    <i class="fas fa-lock"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-group">
                    <input type="text" id="username" name="username" placeholder=" " required>
                    <label class="form-label">Username</label>
                    <i class="field-icon fas fa-user-shield"></i>
                </div>

                <div class="form-group">
                    <input type="password" id="password" name="password" placeholder=" " required>
                    <label class="form-label">Password</label>
                    <i class="field-icon fas fa-key"></i>
                </div>

                <button type="submit" class="btn-login">
                    Secure Login <i class="fas fa-arrow-right"></i>
                </button>

                <div class="bottom-link">
                    <a href="../">
                        <i class="fas fa-arrow-left"></i> Back to Member Portal
                    </a>
                </div>
            </form>
        </div>

    </div>

    <script>
        // ==========================================
        // ADMIN BACKGROUND ANIMATION (Slightly darker/slower)
        // ==========================================
        const canvas = document.getElementById("admin-canvas");
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
            let particleCount = (w * h) / 12000; 
            for (let i = 0; i < particleCount; i++) {
                particles.push(new Particle());
            }
        }

        class Particle {
            constructor() {
                this.x = Math.random() * w;
                this.y = Math.random() * h;
                this.vx = (Math.random() - 0.5) * 0.3; // Very slow
                this.vy = (Math.random() - 0.5) * 0.3;
                this.size = Math.random() * 2 + 1;
            }
            update() {
                this.x += this.vx;
                this.y += this.vy;
                if (this.x < 0 || this.x > w) this.vx *= -1;
                if (this.y < 0 || this.y > h) this.vy *= -1;
            }
            draw() {
                // Darker dots for admin feel
                ctx.fillStyle = "rgba(43, 45, 66, 0.2)"; 
                ctx.beginPath();
                ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        function animationLoop() {
            ctx.clearRect(0, 0, w, h);
            for (let a = 0; a < particles.length; a++) {
                let pA = particles[a];
                pA.update();
                pA.draw();
                for (let b = a; b < particles.length; b++) {
                    let pB = particles[b];
                    let dx = pA.x - pB.x;
                    let dy = pA.y - pB.y;
                    let dist = Math.sqrt(dx*dx + dy*dy);
                    if (dist < 120) {
                        ctx.strokeStyle = `rgba(43, 45, 66, ${0.08 - dist/1500})`;
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