<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];

// --- NEW PHP LOGIC: Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';


// Fetch all unique books read by the member, ordered by last read date
$sql = "
    SELECT 
        t1.book_id,
        t2.title,
        t2.author,
        MAX(t1.read_date) as last_read_date,
        COUNT(t1.learning_id) as read_count
    FROM 
        tbl_learnings t1
    JOIN 
        tbl_books t2 ON t1.book_id = t2.book_id
    WHERE 
        t1.member_id = ?
    GROUP BY
        t1.book_id, t2.title, t2.author
    ORDER BY 
        last_read_date DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$learnings_result = $stmt->get_result();

user_header('My Learnings');
?>

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* --- SHARED VARIABLES (Deep Black Text, Transparent White Glass) --- */
    :root {
        --primary: #4361ee;
        --secondary: #3f37c9;
        --accent: #4cc9f0;
        --text-dark: #111827; /* DEEP BLACK for High Contrast */
        --text-light: #4b5563; /* Darker muted text for contrast */
        --white: #ffffff;
        --card-bg: rgba(255, 255, 255, 0.75); /* White Transparent Glass */
        --shadow: 0 10px 40px rgba(0,0,0,0.3);
    }

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
        position: relative; 
    }

    /* --- NEW WATERMARK STYLE --- */
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
            opacity: 0.3; 
            z-index: -1; 
            pointer-events: none;
        }
    <?php endif; ?>

    .learnings-container {
        max-width: 1000px;
        margin: 40px auto;
        padding: 0 20px;
    }

    /* --- GLASS CARD EFFECT --- */
    .card {
        background: var(--card-bg);
        backdrop-filter: blur(15px); 
        -webkit-backdrop-filter: blur(15px);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.15); /* Darker shadow */
        margin-bottom: 30px;
        border: 1px solid rgba(255, 255, 255, 0.8);
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }


    .card h1 {
        font-family: 'Nunito', sans-serif;
        font-weight: 700;
        font-size: 2rem;
        color: var(--text-dark); /* Deep Black */
        margin-bottom: 20px;
        border-bottom: 2px solid rgba(0, 0, 0, 0.1); /* Darker divider */
        padding-bottom: 15px;
    }

    .custom-table {
        width: 100%;
        border-collapse: collapse;
    }

    .custom-table th {
        text-align: left;
        padding: 15px;
        color: var(--text-light); /* Darker Muted Header Text */
        font-size: 0.85rem;
        text-transform: uppercase;
        font-weight: 700;
        border-bottom: 2px solid rgba(0, 0, 0, 0.15);
        background: rgba(67, 97, 238, 0.1); /* Subtle primary color background */
        color: var(--text-dark); /* Deep Black Header Text */
    }

    .custom-table td {
        padding: 15px;
        font-size: 0.95rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        color: var(--text-dark); /* Deep Black Data Text */
    }

    .custom-table tr:last-child td { border-bottom: none; }
    .custom-table tr:hover { background: rgba(255, 255, 255, 0.9); } /* Brighter hover effect */


    .no-learnings {
        text-align: center;
        padding: 40px;
        color: var(--text-light);
    }
    .no-learnings i {
        font-size: 3rem;
        margin-bottom: 15px;
        color: var(--accent);
    }
    .no-learnings h3 {
        font-weight: 600;
        color: var(--text-dark); /* Deep Black */
    }
    
    .btn-read-again {
        background: var(--primary);
        color: white;
        padding: 8px 15px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.3s;
        display: inline-block;
        box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
    }
    .btn-read-again:hover {
        background: var(--secondary);
        transform: translateY(-2px);
    }
</style>

<div class="learnings-container">
    <div class="card">
        <h1><i class="fas fa-graduation-cap" style="color: var(--primary);"></i> My Learnings History</h1>
        
        <?php if ($learnings_result->num_rows > 0): ?>
            <div style="overflow-x: auto;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Author</th>
                           
                            <th>Last Read</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($learning = $learnings_result->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($learning['title']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($learning['author']); ?></td>
                                
                                <td><?php echo date('M d, Y', strtotime($learning['last_read_date'])); ?></td>
                                <td>
                                    <a href="read_online.php?book_id=<?php echo $learning['book_id']; ?>" target="_blank" class="btn-read-again">
                                        <i class="fas fa-book-open"></i> Read Again
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-learnings">
                <i class="fas fa-book-open"></i>
                <h3>No Learning History Yet</h3>
                <p>Start reading books online to track your progress here!</p>
                <a href="search.php" class="btn-read-again" style="margin-top: 15px;">
                    <i class="fas fa-search"></i> Find Books to Read
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
user_footer();
close_db_connection($conn);
?>