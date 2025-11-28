<?php
require_once 'includes/functions.php';
global $conn;

// --- 1. AJAX Handler for Real-Time Suggestions ---
if (isset($_GET['ajax_suggestions'])) {
    // This block runs only when the Javascript fetches 'search.php?ajax_suggestions=...'
    header('Content-Type: application/json');
    $query = trim($_GET['ajax_suggestions']);
    $suggestions = [];

    if (strlen($query) >= 2) {
        // Search across multiple columns: Title, Author, Category, Library, Content Type
        $sql = "
            SELECT 
                b.title, 
                b.author, 
                b.category, 
                b.cover_image,
                l.library_name, 
                c.type_name
            FROM tbl_books b
            LEFT JOIN tbl_libraries l ON b.library_id = l.library_id
            LEFT JOIN tbl_content_types c ON b.content_type_id = c.content_type_id
            WHERE 
                b.title LIKE ? OR 
                b.author LIKE ? OR 
                b.category LIKE ? OR 
                l.library_name LIKE ? OR 
                c.type_name LIKE ?
            LIMIT 8
        ";
        
        $stmt = $conn->prepare($sql);
        $search_term = "%" . $query . "%";
        $stmt->bind_param("sssss", $search_term, $search_term, $search_term, $search_term, $search_term);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $suggestions[] = $row;
        }
    }
    echo json_encode($suggestions);
    exit; // Stop here so we don't return the rest of the HTML page
}

// --- Standard Page Logic Starts Here ---

// Ensure member_id is set for personalization (favorites/reservations check)
$member_id = $_SESSION['member_id'] ?? null;

// --- Fetch Filter and Search Parameters ---
$search_query = trim($_GET['search'] ?? '');
$filter_type = $_GET['type'] ?? 'all';       
$filter_status = $_GET['status'] ?? 'all';   
$filter_content_type = $_GET['content_type'] ?? 'all';
$filter_library = $_GET['library'] ?? 'all';

// --- Fetch Content Types & Libraries for Dropdowns ---
$content_types = [];
$ct_result = $conn->query("SELECT * FROM tbl_content_types ORDER BY type_name ASC");
while($row = $ct_result->fetch_assoc()) $content_types[] = $row;

$libraries = [];
$lib_result = $conn->query("SELECT * FROM tbl_libraries ORDER BY library_name ASC");
while($row = $lib_result->fetch_assoc()) $libraries[] = $row;

// --- Build SQL Query for Main Results ---
$sql = "SELECT b.*, tbc_base.book_uid AS base_book_uid, l.library_name, c.type_name,
        (SELECT COUNT(*) FROM tbl_favorites tf WHERE tf.member_id = ? AND tf.book_id = b.book_id) AS is_favorite,
        (SELECT COUNT(*) FROM tbl_reservations tr WHERE tr.member_id = ? AND tr.book_id = b.book_id AND tr.status IN ('Pending', 'Accepted')) AS is_reserved
        FROM tbl_books b
        LEFT JOIN tbl_libraries l ON b.library_id = l.library_id
        LEFT JOIN tbl_content_types c ON b.content_type_id = c.content_type_id
        LEFT JOIN tbl_book_copies tbc_base ON b.book_id = tbc_base.book_id AND tbc_base.book_uid LIKE '%-BASE'";

$params = [$member_id, $member_id];
$types = 'ii';
$conditions = [];

// 1. Book Type Filter
if ($filter_type == 'physical') {
    $conditions[] = "b.total_quantity > 0";
} elseif ($filter_type == 'online') {
    $conditions[] = "b.is_online_available = 1";
}

// 2. Availability Status Filter
if ($filter_status == 'available') {
    $conditions[] = "b.available_quantity > 0";
} elseif ($filter_status == 'reserved') {
    $conditions[] = "b.available_quantity = 0";
}

// 3. Content Type Filter
if ($filter_content_type != 'all') {
    $conditions[] = "b.content_type_id = ?";
    $params[] = $filter_content_type;
    $types .= 'i';
}

// 4. Library Filter
if ($filter_library != 'all') {
    $conditions[] = "b.library_id = ?";
    $params[] = $filter_library;
    $types .= 'i';
}

// 5. Search Query Filter
if (!empty($search_query)) {
    $conditions[] = "(b.title LIKE ? OR b.author LIKE ? OR b.category LIKE ? OR b.isbn LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssss';
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY title ASC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
    }
}
$stmt->execute();
$books_result = $stmt->get_result();

// --- Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';

user_header('Search Catalog');
?>

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* --- SHARED VARIABLES --- */
    :root {
        --primary: #4361ee;
        --secondary: #3f37c9;
        --accent: #4cc9f0;
        --text-dark: #111827;
        --text-light: #4b5563;
        --success: #10b981;
        --danger: #ef233c;
        --white: #ffffff;
        --card-bg: rgba(255, 255, 255, 0.9);
        --shadow: 0 10px 30px rgba(0,0,0,0.3);
        --shadow-hover: 0 15px 40px rgba(67, 97, 238, 0.35);
        --border-color: rgba(255, 255, 255, 0.7);
        --unavailable-gray: #6c757d;
    }

    body {
        font-family: 'Poppins', sans-serif;
        color: var(--text-dark);
        background: radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                    radial-gradient(at 50% 100%, hsla(225,39%,25%,1) 0, transparent 50%), 
                    radial-gradient(at 100% 0%, hsla(339,49%,25%,1) 0, transparent 50%),
                    #0f172a;
        background-attachment: fixed; 
        position: relative; 
        margin: 0;
        padding-bottom: 40px;
    }
    
    /* Watermark CSS */
    <?php if (!empty($full_bg_logo_path)): ?>
        body::before {
            content: "";
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 80%; height: 80%;
            background-image: url("<?php echo $full_bg_logo_path; ?>");
            background-repeat: no-repeat;
            background-position: center center;
            background-size: contain;
            opacity: 0.15;
            z-index: -1; 
            pointer-events: none;
        }
    <?php endif; ?>
    
    .search-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 40px 20px;
    }

    .search-header {
        text-align: center;
        margin-bottom: 30px;
    }
    .search-header h1 { 
        color: var(--white); 
        text-shadow: 0 0 10px rgba(0,0,0,0.5); 
        font-size: 2.2rem;
        margin-bottom: 10px;
    }
    .search-header p { color: #cbd5e1; font-size: 1rem; }

    /* --- SEARCH BAR --- */
    .search-box-wrapper {
        position: relative; 
        display: flex; 
        align-items: stretch;
        max-width: 700px; 
        margin: 30px auto;
        background: var(--card-bg); 
        backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        border-radius: 50px; 
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        border: 2px solid var(--border-color);
        transition: all 0.3s ease;
        z-index: 500;
    }
    .search-box-wrapper:focus-within { 
        border-color: var(--primary); 
        box-shadow: 0 10px 40px rgba(67, 97, 238, 0.4); 
    }
    
    .search-input { 
        flex-grow: 1; 
        padding: 15px 20px 15px 55px; 
        border: none; 
        background: transparent; 
        font-size: 1rem; 
        color: var(--text-dark); 
        outline: none; 
        border-radius: 50px 0 0 50px;
        width: 100%;
    }
    
    .search-icon-left { 
        position: absolute; 
        left: 20px; 
        top: 50%; 
        transform: translateY(-50%); 
        color: var(--text-light); 
        font-size: 1.2rem; 
        pointer-events: none; 
        z-index: 1; 
    }
    
    .search-btn { 
        background: linear-gradient(135deg, var(--primary), var(--secondary)); 
        color: var(--white); 
        border: none; 
        padding: 0 35px; 
        font-weight: 600; 
        cursor: pointer; 
        transition: 0.3s; 
        font-size: 1rem; 
        border-radius: 0 50px 50px 0;
        white-space: nowrap;
    }
    .search-btn:hover { background: linear-gradient(135deg, var(--secondary), var(--primary)); }

    /* --- SEARCH SUGGESTIONS --- */
    .suggestions-list {
        position: absolute; top: 100%; left: 15px; right: 15px;
        background: #ffffff; border-radius: 0 0 15px 15px;
        box-shadow: 0 15px 30px rgba(0,0,0,0.2); max-height: 350px;
        overflow-y: auto; z-index: 1000; display: none; margin-top: 5px;
        border: 1px solid #e2e8f0; border-top: none;
    }
    .suggestion-item {
        padding: 12px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9;
        transition: background 0.2s; color: var(--text-dark); text-align: left;
    }
    .suggestion-item:last-child { border-bottom: none; }
    .suggestion-item:hover { background: #f8fafc; }
    
    .sugg-title { font-weight: 700; font-size: 0.95rem; color: var(--primary); display: block; }
    .sugg-meta { font-size: 0.8rem; color: var(--text-light); display: block; margin-top: 2px; }
    .sugg-tag { 
        background: #e0f2fe; color: #0369a1; 
        padding: 2px 6px; border-radius: 4px; 
        font-size: 0.7rem; font-weight: 600; margin-left: 5px; display: inline-block;
    }

    /* --- FILTER BAR (RESPONSIVE GRID) --- */
    .filter-bar {
        max-width: 1000px; 
        margin: 20px auto 40px auto; 
        padding: 20px;
        background: rgba(255, 255, 255, 0.1); 
        backdrop-filter: blur(5px);
        border-radius: 15px; 
        box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .filter-bar form {
        display: flex;
        flex-wrap: wrap; 
        gap: 15px;
        align-items: flex-end;
        width: 100%;
    }

    .filter-group { 
        display: flex; 
        flex-direction: column; 
        flex: 1 1 200px; /* Responsive wrapping */
        min-width: 140px; 
    }
    
    .filter-group label { color: var(--white); margin-bottom: 5px; font-weight: 500; font-size: 0.85rem; }
    
    .filter-group select {
        width: 100%;
        padding: 10px; border-radius: 8px; border: 2px solid var(--border-color);
        background: rgba(255, 255, 255, 0.95); color: var(--text-dark); font-size: 0.95rem;
        appearance: none; cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%234361ee'%3E%3Cpath fill-rule='evenodd' d='M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z' clip-rule='evenodd'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 10px center; background-size: 1em;
    }
    .filter-group select:focus { border-color: var(--primary); outline: none; }
    
    .filter-bar button {
        flex: 0 0 auto;
        padding: 12px 20px; 
        background: var(--danger); color: var(--white);
        border: none; border-radius: 8px; font-weight: 600; cursor: pointer; 
        transition: background 0.2s; white-space: nowrap;
        height: 46px; 
    }
    .filter-bar button:hover { background: #cc2a3f; }

    /* --- RESULTS GRID --- */
    .results-grid {
        display: flex; 
        flex-direction: column;
        gap: 25px; 
        margin-top: 40px;
    }

    /* --- BOOK CARD (DESKTOP DEFAULT) --- */
    .book-card {
        background: var(--card-bg); 
        backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
        border-radius: 15px; 
        box-shadow: var(--shadow);
        transition: all 0.3s ease; 
        position: relative; 
        overflow: hidden; 
        border: 1px solid var(--border-color);
        display: flex; 
        flex-direction: row; 
        min-height: 220px;
    }
    .book-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-hover); border-color: var(--primary); }
    
    .book-card::before { 
        content: ''; position: absolute; top: 0; left: 0; 
        width: 5px; height: 100%; 
        background: linear-gradient(180deg, var(--primary), var(--accent)); 
        z-index: 2; 
    }
    
    .book-cover-area {
        width: 200px;
        min-width: 200px;
        background: #f1f5f9;
        position: relative;
        overflow: hidden;
    }
    .book-cover-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s; }
    .book-card:hover .book-cover-img { transform: scale(1.05); }
    
    .no-cover-placeholder { 
        width: 100%; height: 100%; display: flex; 
        align-items: center; justify-content: center; 
        color: #cbd5e1; font-size: 3rem; 
    }

    .book-content { 
        padding: 20px 25px; 
        flex-grow: 1; 
        display: flex; 
        flex-direction: column; 
    }
    
    .book-main-info h3 { 
        color: var(--text-dark); margin: 0 0 5px 0; 
        font-size: 1.3rem; line-height: 1.3; 
    }
    .book-author { color: var(--text-light); display: block; margin-bottom: 12px; font-size: 0.95rem; font-style: italic;}
    
    .book-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 15px; }
    .meta-tag { 
        background: rgba(255,255,255,0.6); border: 1px solid #e2e8f0;
        color: var(--text-dark); padding: 4px 10px; 
        border-radius: 6px; font-size: 0.8rem; font-weight: 500; 
    }
    .meta-tag i { margin-right: 5px; color: var(--primary); }
    
    .card-footer { 
        margin-top: auto; padding-top: 15px; border-top: 1px solid rgba(0,0,0,0.08); 
        display: flex; justify-content: space-between; align-items: center; gap: 15px; 
    }
    
    .shelf-loc { color: var(--text-light); font-size: 0.9rem; font-weight: 600; }
    .action-buttons { display: flex; gap: 8px; align-items: center; }
    
    .action-buttons button, .action-buttons a {
        padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; 
        cursor: pointer; transition: all 0.2s ease; border: none; color: var(--white); 
        text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
    }
    .action-buttons i { margin-right: 6px; }

    /* Button Colors */
    .btn-reserve { background: var(--primary); }
    .btn-reserve:hover { background: var(--secondary); }
    .btn-reserve.reserved, .btn-reserve[disabled] { background: var(--unavailable-gray) !important; cursor: not-allowed; opacity: 0.8; }
    .btn-read-online { background: var(--accent); color: #fff; }
    .btn-read-online:hover { background: #3cb8da; }
    .btn-favorite { background: #ffddd2; color: #d64045 !important; }
    .btn-favorite:hover { background: #ffccd5; }
    .btn-favorite.favorited { background: var(--danger); color: white !important; }
    .btn-favorite.favorited:hover { background: #c82333; }

    .status-pill { 
        padding: 6px 12px; border-radius: 20px; font-weight: 700; 
        font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap;
    }
    .status-available { background: var(--success); color: var(--white); }
    .status-unavailable { background: var(--danger); color: var(--white); }
    .status-online-only { background: var(--accent); color: var(--white); }
    
    .no-results {
        text-align: center; padding: 60px 20px; color: var(--white);
        background: rgba(0,0,0,0.3); backdrop-filter: blur(5px); border-radius: 15px;
    }
    .no-results i { opacity: 0.6; font-size: 3rem; margin-bottom: 20px; }

    /* --- RESPONSIVE BREAKPOINTS --- */
    
    /* Tablet View (max-width: 992px) */
    @media (max-width: 992px) {
        .book-card { min-height: 180px; }
        .book-cover-area { width: 150px; min-width: 150px; }
        .book-content { padding: 15px; }
        .book-main-info h3 { font-size: 1.15rem; }
        .filter-group { flex: 1 1 45%; }
    }

    /* Mobile View (max-width: 768px) */
    @media (max-width: 768px) {
        .search-container { padding: 20px 15px; }
        .search-header h1 { font-size: 1.8rem; }
        
        /* Stack the search bar */
        .search-box-wrapper { 
            flex-direction: column; 
            background: transparent; border: none; box-shadow: none; 
            border-radius: 0; gap: 10px;
        }
        .search-input { 
            background: var(--card-bg); 
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 15px 15px 15px 50px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .search-btn { 
            width: 100%; border-radius: 12px; padding: 15px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.3);
        }
        .search-icon-left { top: 28px; left: 20px; }
        
        /* Stack the book card */
        .book-card { flex-direction: column; height: auto; }
        .book-card::before { width: 100%; height: 5px; background: linear-gradient(90deg, var(--primary), var(--accent)); }
        
        .book-cover-area { 
            width: 100%; 
            min-width: 100%; 
            height: 250px; 
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .card-footer { 
            flex-direction: column; 
            align-items: flex-start; 
            gap: 15px; 
        }
        .action-buttons { width: 100%; }
        .action-buttons button, .action-buttons a { flex: 1; padding: 12px; }
        
        .filter-group { flex: 1 1 100%; }
        .filter-bar button { width: 100%; margin-top: 10px; }
    }

    /* --- CUSTOM MODAL --- */
    #custom-modal-overlay {
        position: fixed; top: 0; left: 0; right: 0; bottom: 0;
        background: rgba(0, 0, 0, 0.8); display: none;
        justify-content: center; align-items: center; z-index: 2000;
        opacity: 0; transition: opacity 0.3s ease;
        padding: 20px;
    }
    #custom-modal-overlay.active { display: flex; opacity: 1; }
    #custom-modal {
        background: #fff; border-radius: 15px; padding: 25px; 
        box-shadow: 0 10px 50px rgba(0, 0, 0, 0.5);
        width: 100%; max-width: 400px; color: var(--text-dark); text-align: center;
        transform: scale(0.9); transition: transform 0.3s ease;
    }
    #custom-modal-overlay.active #custom-modal { transform: scale(1); }
    #modal-title { font-size: 1.4rem; font-weight: 700; color: var(--primary); margin-bottom: 10px; }
    #modal-message { margin-bottom: 25px; color: var(--text-light); line-height: 1.5; font-size: 0.95rem; }
    #modal-actions { display: flex; justify-content: center; gap: 15px; }
    #modal-actions button { padding: 10px 25px; border-radius: 8px; font-weight: 600; cursor: pointer; border: none; }
    #modal-actions .btn-primary { background: var(--primary); color: var(--white); }
    #modal-actions .btn-secondary { background: var(--unavailable-gray); color: var(--white); }

    /* --- LOADING OVERLAY --- */
    #loadingOverlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(5px);
        z-index: 9999;
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }
    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #e0e7ff;
        border-top: 5px solid #4361ee; /* Primary color */
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 15px;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

</style>

<div id="loadingOverlay">
    <div class="spinner"></div>
    <div style="font-weight: 600; color: #1e293b;">Processing Reservation...</div>
</div>

<div class="search-container">
    
    <div class="search-header">
        <h1>Explore Our Collection</h1>
        <p>Find knowledge, inspiration, and resources instantly.</p>
        
        <form method="GET" action="search.php" id="main-search-form">
            <div class="search-box-wrapper">
                <i class="fas fa-search search-icon-left"></i>
                <input type="text" id="search-input" name="search" class="search-input" 
                       placeholder="Search by Title, Author, or ISBN..." 
                       value="<?php echo htmlspecialchars($search_query); ?>" 
                       autocomplete="off">
                <button type="submit" class="search-btn">Search</button>
                
                <div id="suggestion-box" class="suggestions-list"></div>
            </div>
        </form>
    </div>

    <div class="filter-bar">
        <form method="GET" action="search.php" id="filter-form">
            <?php if (!empty($search_query)): ?>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
            <?php endif; ?>

            <div class="filter-group">
                <label for="filter-type">Book Type</label>
                <select name="type" id="filter-type" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter_type == 'all') ? 'selected' : ''; ?>>All Types</option>
                    <option value="physical" <?php echo ($filter_type == 'physical') ? 'selected' : ''; ?>>Physical Copy</option>
                    <option value="online" <?php echo ($filter_type == 'online') ? 'selected' : ''; ?>>E-Read / Digital</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="filter-status">Availability</label>
                <select name="status" id="filter-status" onchange="this.form.submit()">
                    <option value="all" <?php echo ($filter_status == 'all') ? 'selected' : ''; ?>>All Statuses</option>
                    <option value="available" <?php echo ($filter_status == 'available') ? 'selected' : ''; ?>>Available Now</option>
                    <option value="reserved" <?php echo ($filter_status == 'reserved') ? 'selected' : ''; ?>>Issued Out</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="filter-content-type">Content Type</label>
                <select name="content_type" id="filter-content-type" onchange="this.form.submit()">
                    <option value="all">All Content Types</option>
                    <?php foreach($content_types as $ct): ?>
                        <option value="<?php echo $ct['content_type_id']; ?>" <?php echo ($filter_content_type == $ct['content_type_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ct['type_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="filter-library">Library</label>
                <select name="library" id="filter-library" onchange="this.form.submit()">
                    <option value="all">All Libraries</option>
                    <?php foreach($libraries as $lib): ?>
                        <option value="<?php echo $lib['library_id']; ?>" <?php echo ($filter_library == $lib['library_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($lib['library_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="window.location.href='search.php'">
                <i class="fas fa-redo"></i> Reset Filters
            </button>
        </form>
    </div>

    <div class="results-grid">
        <?php if ($books_result->num_rows > 0): ?>
            <?php while ($book = $books_result->fetch_assoc()): 
                $available = $book['available_quantity'];
                $total = $book['total_quantity'];
                $is_avail = $available > 0;
                $is_online_read_only = ($total == 0 && $book['is_online_available'] == 1);
                $base_uid_raw = $book['base_book_uid'] ?? 'N/A';
                $base_uid_display = ($base_uid_raw !== 'N/A' && substr($base_uid_raw, -5) === '-BASE') ? substr($base_uid_raw, 0, -5) : 'N/A';
            ?>
                <div class="book-card">
                    <div class="book-cover-area">
                        <?php if (!empty($book['cover_image'])): ?>
                            <img src="<?php echo htmlspecialchars($book['cover_image']); ?>" alt="Cover" class="book-cover-img">
                        <?php else: ?>
                            <div class="no-cover-placeholder"><i class="fas fa-book"></i></div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-content">
                        <div class="book-main-info">
                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                            <span class="book-author">by <?php echo htmlspecialchars($book['author']); ?></span>
                        </div>

                        <div class="book-meta">
                            <div class="meta-tag" title="Category"><i class="fas fa-folder"></i> Category: <?php echo htmlspecialchars($book['category']); ?></div>
                            
                            <?php if (!empty($book['edition'])): ?>
                                <div class="meta-tag" title="Edition"><i class="fas fa-bookmark"></i> Edition: <?php echo htmlspecialchars($book['edition']); ?></div>
                            <?php endif; ?>
                            
                            <div class="meta-tag" title="ISBN"><i class="fas fa-barcode"></i> ISBN: <?php echo htmlspecialchars($book['isbn']); ?></div>
                            <div class="meta-tag" title="Content Type"><i class="fas fa-file-alt"></i> Type: <?php echo htmlspecialchars($book['type_name'] ?? 'Book'); ?></div>
                            <div class="meta-tag" title="Library"><i class="fas fa-university"></i> Library: <?php echo htmlspecialchars($book['library_name'] ?? 'General'); ?></div>
                        </div>

                        <div class="card-footer">
                            <div style="display: flex; flex-direction: column; gap: 5px;">
                                <div class="shelf-loc">
                                    <i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> Shelf: <?php echo htmlspecialchars($book['shelf_location']); ?>
                                </div>
                                
                                <?php if ($is_avail): ?>
                                    <div class="status-pill status-available">Available (<?php echo $available; ?>)</div>
                                <?php elseif ($is_online_read_only): ?>
                                    <div class="status-pill status-online-only">Online Read Only</div>
                                <?php else: ?>
                                    <div class="status-pill status-unavailable">Issued Out</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="action-buttons">
                                <?php if ($book['is_online_available'] == 1 && !empty($book['soft_copy_path'])): ?>
                                    <a href="read_online.php?book_id=<?php echo $book['book_id']; ?>" target="_blank" class="btn-read-online">
                                        <i class="fas fa-file-pdf"></i> Read
                                    </a>
                                <?php endif; ?>

                                <button class="btn-favorite <?php echo $book['is_favorite'] ? 'favorited' : ''; ?>" 
                                        data-book-id="<?php echo $book['book_id']; ?>" 
                                        data-is-favorite="<?php echo $book['is_favorite'] ? '1' : '0'; ?>"
                                        data-book-title="<?php echo htmlspecialchars($book['title']); ?>"
                                        onclick="toggleFavorite(this)">
                                    <i class="fas fa-heart"></i> 
                                </button>

                                <?php if ($is_avail && !$book['is_reserved']): ?>
                                    <button class="btn-reserve" 
                                            data-book-id="<?php echo $book['book_id']; ?>" 
                                            data-title="<?php echo htmlspecialchars($book['title']); ?>"
                                            data-author="<?php echo htmlspecialchars($book['author']); ?>"
                                            data-category="<?php echo htmlspecialchars($book['category']); ?>"
                                            data-isbn="<?php echo htmlspecialchars($book['isbn'] ?: 'N/A'); ?>"
                                            data-location="<?php echo htmlspecialchars($book['shelf_location']); ?>"
                                            data-base-uid="<?php echo htmlspecialchars($base_uid_display); ?>"
                                            onclick="reserveBook(this)">
                                        <i class="fas fa-calendar-plus"></i> Reserve
                                    </button>
                                <?php elseif ($book['is_reserved']): ?>
                                    <button class="btn-reserve reserved" disabled>
                                        <i class="fas fa-clock"></i> Reserved
                                    </button>
                                <?php elseif (!$is_online_read_only): ?>
                                    <button class="btn-reserve" 
                                            data-book-id="<?php echo $book['book_id']; ?>" 
                                            data-title="<?php echo htmlspecialchars($book['title']); ?>"
                                            data-author="<?php echo htmlspecialchars($book['author']); ?>"
                                            data-category="<?php echo htmlspecialchars($book['category']); ?>"
                                            data-isbn="<?php echo htmlspecialchars($book['isbn'] ?: 'N/A'); ?>"
                                            data-location="<?php echo htmlspecialchars($book['shelf_location']); ?>"
                                            data-base-uid="<?php echo htmlspecialchars($base_uid_display); ?>"
                                            onclick="reserveBook(this)">
                                        <i class="fas fa-calendar-plus"></i> Waitlist
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div> 
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-results">
                <i class="fas fa-book-open"></i>
                <h2>No books found</h2>
                <p>We couldn't find any matches for "<?php echo htmlspecialchars($search_query); ?>".<br>Try adjusting your search or filters.</p>
                <?php if (!empty($search_query) || $filter_type != 'all' || $filter_status != 'all'): ?>
                    <a href="search.php" style="display: inline-block; margin-top: 20px; color: var(--primary); font-weight: 600; text-decoration: none;">
                        <i class="fas fa-arrow-left"></i> View All Books
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="custom-modal-overlay" class="">
    <div id="custom-modal">
        <h3 id="modal-title"></h3>
        <p id="modal-message"></p>
        <div id="modal-actions">
            <button id="modal-cancel" class="btn-secondary">Cancel</button>
            <button id="modal-confirm" class="btn-primary">OK</button>
        </div>
    </div>
</div>

<script>
    const isLoggedIn = "<?php echo is_member_logged_in() ? '1' : '0'; ?>" === "1";
    let modalCallback = null;

    // --- REAL-TIME SUGGESTION JAVASCRIPT ---
    const searchInput = document.getElementById('search-input');
    const suggestionBox = document.getElementById('suggestion-box');
    let debounceTimer;

    // Listen for user input
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        // Hide if query is too short
        if (query.length < 2) {
            suggestionBox.style.display = 'none';
            return;
        }

        // Clear existing timer
        if (debounceTimer) clearTimeout(debounceTimer);

        // Set new timer to fetch data (Debouncing to reduce server load)
        debounceTimer = setTimeout(() => {
            fetch('search.php?ajax_suggestions=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    suggestionBox.innerHTML = ''; // Clear previous suggestions
                    
                    if (data.length > 0) {
                        data.forEach(item => {
                            const div = document.createElement('div');
                            div.className = 'suggestion-item';
                            
                            // Rich formatting for the suggestion item
                            const coverHtml = item.cover_image 
                                ? `<img src="${item.cover_image}" style="width:40px; height:60px; object-fit:cover; border-radius:4px; margin-right:10px;">` 
                                : `<div style="width:40px; height:60px; background:#f1f5f9; border-radius:4px; margin-right:10px; display:flex; align-items:center; justify-content:center; color:#cbd5e1;"><i class="fas fa-book"></i></div>`;

                            div.innerHTML = `
                                <div style="display:flex; align-items:center;">
                                    ${coverHtml}
                                    <div>
                                        <span class="sugg-title">${item.title}</span>
                                        <span class="sugg-meta">
                                            <i class="fas fa-user"></i> ${item.author} 
                                            <span class="sugg-tag">${item.type_name || 'Book'}</span>
                                            <span class="sugg-tag" style="background:#f1f5f9; color:#64748b;">${item.library_name || 'General'}</span>
                                        </span>
                                    </div>
                                </div>
                            `;
                            
                            // Click handler: populate input and submit
                            div.onclick = function() {
                                searchInput.value = item.title; 
                                suggestionBox.style.display = 'none';
                                document.getElementById('main-search-form').submit(); 
                            };
                            
                            suggestionBox.appendChild(div);
                        });
                        suggestionBox.style.display = 'block'; // Show results
                    } else {
                        suggestionBox.style.display = 'none'; // Hide if no matches
                    }
                })
                .catch(err => {
                    console.error('Error fetching suggestions:', err);
                    suggestionBox.style.display = 'none';
                });
        }, 300); // Wait 300ms after typing stops
    });

    // Hide suggestions if clicking outside the search area
    document.addEventListener('click', function(e) {
        if (!document.querySelector('.search-box-wrapper').contains(e.target)) {
            suggestionBox.style.display = 'none';
        }
    });

    // --- Modal & Interaction Logic (Existing) ---
    function showCustomModal(title, message, isConfirm = false, callback = null) {
        const overlay = document.getElementById('custom-modal-overlay');
        const modalTitle = document.getElementById('modal-title');
        const modalMessage = document.getElementById('modal-message'); 
        const btnCancel = document.getElementById('modal-cancel');
        const btnConfirm = document.getElementById('modal-confirm');

        modalTitle.textContent = title;
        modalMessage.innerHTML = message; 
        modalCallback = callback;

        if (isConfirm) {
            btnCancel.style.display = 'inline-block';
            btnConfirm.textContent = 'Confirm';
        } else {
            btnCancel.style.display = 'none';
            btnConfirm.textContent = 'OK';
        }
        
        overlay.classList.add('active');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const overlay = document.getElementById('custom-modal-overlay');
        const btnCancel = document.getElementById('modal-cancel');
        const btnConfirm = document.getElementById('modal-confirm');

        function closeModal(result) {
            overlay.classList.remove('active');
            if (modalCallback) {
                modalCallback(result);
                modalCallback = null;
            }
        }

        btnCancel.onclick = () => closeModal(false);
        btnConfirm.onclick = () => closeModal(true);
        overlay.onclick = (e) => { if (e.target === overlay) closeModal(false); };
    });

    function showNotification(message, type = 'success') {
        const title = type === 'success' ? 'Success' : 'Error';
        showCustomModal(title, message, false);
    }

    function toggleFavorite(button) {
        if (!isLoggedIn) { window.location.href = 'login.php'; return; }
        const bookId = button.getAttribute('data-book-id');
        const isFavorite = button.getAttribute('data-is-favorite') === '1';
        const bookTitle = button.getAttribute('data-book-title') || 'Book';
        const action = isFavorite ? 'remove' : 'add';

        fetch('favorites_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=${action}&book_id=${bookId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (action === 'add') {
                    button.classList.add('favorited');
                    button.setAttribute('data-is-favorite', '1');
                    // button.querySelector('.fav-text').textContent = 'Favorited';
                    showNotification(`Added <strong>${bookTitle}</strong> to favorites`, 'success');
                } else {
                    button.classList.remove('favorited');
                    button.setAttribute('data-is-favorite', '0');
                    // button.querySelector('.fav-text').textContent = 'Favorite';
                    showNotification(`Removed <strong>${bookTitle}</strong> from favorites`, 'success');
                }
            } else {
                showNotification('Error: ' + data.message, 'error');
            }
        })
        .catch(error => showNotification('An unexpected error occurred.', 'error'));
    }

    function reserveBook(button) {
        if (!isLoggedIn) { window.location.href = 'login.php'; return; }
        const bookId = button.getAttribute('data-book-id');
        const bookDetails = {
            title: button.getAttribute('data-title'),
            author: button.getAttribute('data-author'),
            category: button.getAttribute('data-category'),
            isbn: button.getAttribute('data-isbn'),
            location: button.getAttribute('data-location'),
            base_uid: button.getAttribute('data-base-uid')
        };

        let messageHtml = `
            <div style="text-align: left; margin-bottom: 20px; padding: 15px; border-radius: 8px; background: #f8faff; border: 1px solid #e0e7ff; color: var(--text-dark);">
                <p style="font-size: 1.1rem; font-weight: 700; color: var(--primary); margin-bottom: 10px; border-bottom: 1px solid #e0e7ff; padding-bottom: 5px;">${bookDetails.title}</p>
                <div style="font-size: 0.9rem; color: var(--text-light);">
                    <p style="margin: 3px 0;"><strong style="color: var(--text-dark);">Author:</strong> ${bookDetails.author}</p>
                    <p style="margin: 3px 0;"><strong style="color: var(--text-dark);">Category:</strong> ${bookDetails.category}</p>
                    <p style="margin: 3px 0;"><strong style="color: var(--text-dark);">ISBN:</strong> ${bookDetails.isbn}</p>
                    <p style="margin: 3px 0;"><strong style="color: var(--text-dark);">Location:</strong> ${bookDetails.location}</p>
                    <p style="margin: 3px 0;"><strong style="color: var(--text-dark);">ID:</strong> <span style="font-family: monospace; color: var(--text-dark); background: rgba(0,0,0,0.05); padding: 2px 5px; border-radius: 4px;">${bookDetails.base_uid}</span></p>
                </div>
            </div>
            Are you sure you want to reserve this book?
        `;
        
        showCustomModal('Confirm Reservation', messageHtml, true, (confirmed) => {
            if (confirmed) {
                // Show Loading Overlay
                document.getElementById('loadingOverlay').style.display = 'flex';

                fetch('reservation_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=reserve&book_id=${bookId}`
                })
                .then(response => response.json())
                .then(data => {
                    // Hide Loading Overlay
                    document.getElementById('loadingOverlay').style.display = 'none';

                    if (data.success) {
                        showNotification('Reservation successful!<br>Go to Reservation Page for Qr Code<br>Reservation ID: ' + data.reservation_uid, 'success');
                        button.classList.add('reserved');
                        button.setAttribute('disabled', 'disabled');
                        button.innerHTML = '<i class="fas fa-clock"></i> Reserved';
                        // Try to update status pill if visible
                        const cardFooter = button.closest('.card-footer');
                        if(cardFooter) {
                            const statusPill = cardFooter.querySelector('.status-pill');
                            if (statusPill) {
                                statusPill.textContent = 'Reserved';
                                statusPill.className = 'status-pill status-unavailable';
                            }
                        }
                    } else {
                        showNotification('Reservation failed: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    // Hide Loading Overlay
                    document.getElementById('loadingOverlay').style.display = 'none';
                    showNotification('An unexpected error occurred.', 'error')
                });
            }
        });
    }
</script>
    
<?php
user_footer();
close_db_connection($conn);
?>