<?php
require_once 'includes/functions.php';
global $conn;

// Redirect to member login if not logged in
if (!is_member_logged_in()) {
    redirect('login.php');
}

user_header('Welcome');

// --- NEW PHP LOGIC: Get Logo Path for Watermark and Header Logo ---
// The logo path is stored relative to the project root, which is the location of index.php.
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';
?>

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* --- SHARED VARIABLES (MATCHING DASHBOARD) --- */
    :root {
        --primary: #4361ee;
        --secondary: #3f37c9;
        --accent: #4cc9f0;
        --bg-body: #f8f9fa;
        --text-dark: #111827; /* DEEP BLACK for High Contrast */
        --text-light: #4b5563; /* Darker muted text for contrast */
        --white: #ffffff;
        --shadow: 0 10px 30px rgba(0,0,0,0.05);
        --shadow-hover: 0 15px 35px rgba(67, 97, 238, 0.15);
    }

    /* --- MODIFIED BODY STYLES (MATCHING ADMIN DASHBOARD) --- */
    body {
        font-family: 'Poppins', sans-serif;
        color: var(--text-dark);
        /* Admin Dashboard Dark Gradient Background */
        background: radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                    radial-gradient(at 50% 100%, hsla(225,39%,25%,1) 0, transparent 50%), 
                    radial-gradient(at 100% 0%, hsla(339,49%,25%,1) 0, transparent 50%),
                    #0f172a;
        background-attachment: fixed; 
        background-size: cover;
        position: relative; /* Needed for ::before watermark */
    }

    /* --- NEW WATERMARK STYLE on body::before (from admin_header logic) --- */
    <?php if (!empty($full_bg_logo_path)): ?>
        body::before {
            content: "";
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            height: 80%;
            background-image: url("<?php echo $full_bg_logo_path; ?>");
            background-repeat: no-repeat;
            background-position: center center;
            background-size: contain;
            opacity: 0.3; /* Increased opacity for visibility against dark background */
            z-index: -1; /* Behind content */
            pointer-events: none;
        }
    <?php endif; ?>

    /* --- LAYOUT CONTAINER --- */
    .launchpad-container {
        min-height: 80vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }

    .main-card {
        /* Adjusted for the new dark background - semi-transparent white (Glass Effect) */
        background: rgba(255, 255, 255, 0.7); /* DECREASED OPACITY TO 0.7 for more transparency */
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        width: 100%;
        max-width: 800px;
        border-radius: 24px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.4); /* Darker shadow for dark background */
        overflow: hidden;
        animation: slideUp 0.6s ease-out;
        color: var(--text-dark);
    }

    @keyframes slideUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* --- HERO SECTION --- */
    .hero-section {
        background: linear-gradient(135deg, var(--primary), var(--secondary));
        padding: 50px 40px;
        text-align: center;
        color: var(--white);
        position: relative;
        overflow: hidden;
    }

    /* Decorative Circles */
    .hero-section::before {
        content: ''; position: absolute; top: -50px; left: -50px;
        width: 200px; height: 200px; background: rgba(255,255,255,0.1);
        border-radius: 50%;
    }
    .hero-section::after {
        content: ''; position: absolute; bottom: -30px; right: -30px;
        width: 150px; height: 150px; background: rgba(255,255,255,0.1);
        border-radius: 50%;
    }

    .hero-content { position: relative; z-index: 2; }

    /* NEW STYLES FOR EMBEDDED LOGO */
    .inst-logo {
        height: 80px; /* Matched size of the old hero icon */
        margin: 0 auto 20px auto;
        filter: drop-shadow(0 4px 6px rgba(0,0,0,0.2));
    }
    
    .hero-icon {
        /* Hidden as logo takes its place */
        display: none; 
    }

    .hero-section h1 {
        font-family: 'Nunito', sans-serif;
        font-weight: 700;
        font-size: 2rem;
        margin-bottom: 10px;
    }

    .hero-section p {
        font-size: 1rem;
        opacity: 0.9;
        max-width: 500px;
        margin: 0 auto;
    }

    /* --- ACTION GRID --- */
    .menu-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 25px;
        padding: 40px;
    }

    .menu-item {
        /* Keep as solid white card with small border */
        background: var(--white);
        border: 2px solid #f1f3f5;
        border-radius: 16px;
        padding: 25px;
        text-decoration: none;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    /* Icon Styling inside cards */
    .menu-icon-box {
        width: 60px; height: 60px;
        border-radius: 14px;
        display: flex; justify-content: center; align-items: center;
        font-size: 1.5rem;
        margin-bottom: 15px;
        transition: 0.3s;
    }

    .icon-blue { background: #e0f3ff; color: var(--primary); }
    .icon-cyan { background: #dcfce7; color: #10b981; } /* Search */
    .icon-purple { background: #f3e8ff; color: #9333ea; } /* Profile */
    .icon-orange { background: #ffedd5; color: #f97316; } /* Fines */

    .menu-item h3 {
        color: var(--text-dark); /* Deep Black Title */
        font-size: 1.1rem;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .menu-item p {
        color: var(--text-light); /* Dark Muted Text */
        font-size: 0.85rem;
        margin: 0;
    }

    /* Hover Effects */
    .menu-item:hover {
        transform: translateY(-5px);
        border-color: var(--primary);
        box-shadow: 0 15px 35px rgba(67, 97, 238, 0.2); /* Darker hover shadow */
    }

    .menu-item:hover .icon-blue { background: var(--primary); color: white; }
    .menu-item:hover .icon-cyan { background: #10b981; color: white; }
    .menu-item:hover .icon-purple { background: #9333ea; color: white; }
    .menu-item:hover .icon-orange { background: #f97316; color: white; }

    .menu-item::after {
        content: '\f061'; /* FontAwesome Arrow */
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        top: 20px; right: 20px;
        opacity: 0;
        transform: translateX(-10px);
        transition: 0.3s;
        color: var(--text-light);
    }

    .menu-item:hover::after {
        opacity: 1;
        transform: translateX(0);
    }

    /* Mobile Adjustments */
    @media (max-width: 600px) {
        .hero-section { padding: 30px 20px; }
        .hero-section h1 { font-size: 1.5rem; }
        .menu-grid { padding: 25px; gap: 15px; grid-template-columns: 1fr; }
        .menu-item { flex-direction: row; text-align: left; padding: 15px; align-items: center; }
        .menu-icon-box { width: 50px; height: 50px; font-size: 1.2rem; margin-bottom: 0; margin-right: 15px; }
        .menu-item::after { display: none; }
    }

</style>

<div class="launchpad-container">
    <div class="main-card">
        
        <div class="hero-section">
            <div class="hero-content">
                <?php if (!empty($full_bg_logo_path)): ?>
                    <img src="<?php echo $full_bg_logo_path; ?>" class="inst-logo" alt="Institution Logo">
                <?php else: ?>
                    <div class="hero-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                <?php endif; ?>

                <h1>Welcome Back, <?php echo htmlspecialchars($_SESSION['member_full_name'] ?? 'Student'); ?>!</h1>
                <p>Your digital library gateway. Access resources, manage your account, and track your reading journey.</p>
            </div>
        </div>

        <div class="menu-grid">
            
            <a href="dashboard.php" class="menu-item">
                <div class="menu-icon-box icon-blue">
                    <i class="fas fa-columns"></i>
                </div>
                <div>
                    <h3>My Dashboard</h3>
                    <p>Overview & Stats</p>
                </div>
            </a>

            <a href="search.php" class="menu-item">
                <div class="menu-icon-box icon-cyan">
                    <i class="fas fa-search"></i>
                </div>
                <div>
                    <h3>Search Catalog</h3>
                    <p>Find Books & Journals</p>
                </div>
            </a>

            <a href="dashboard.php#profile" class="menu-item">
                <div class="menu-icon-box icon-purple">
                    <i class="fas fa-user-cog"></i>
                </div>
                <div>
                    <h3>Account Settings</h3>
                    <p>Update Profile & Security</p>
                </div>
            </a>

	            <a href="dashboard.php#borrowed" class="menu-item">
	                <div class="menu-icon-box icon-orange">
	                    <i class="fas fa-book-open"></i>
	                </div>
	                <div>
	                    <h3>My Loans</h3>
	                    <p>Check Due Dates</p>
	                </div>
	            </a>
	            
	            <a href="learnings.php" class="menu-item">
	                <div class="menu-icon-box" style="background: #e3f9f5; color: #2ec4b6;">
	                    <i class="fas fa-graduation-cap"></i>
	                </div>
	                <div>
	                    <h3>My Learnings</h3>
	                    <p>Books Read Online</p>
	                </div>
	            </a>
	            
	            <a href="favorites.php" class="menu-item">
	                <div class="menu-icon-box" style="background: #ffe6e6; color: #dc3545;">
	                    <i class="fas fa-heart"></i>
	                </div>
	                <div>
	                    <h3>My Favourites</h3>
	                    <p>Saved Books</p>
	                </div>
	            </a>

        </div>
    </div>
</div>

<?php
user_footer();
close_db_connection($conn);
?>