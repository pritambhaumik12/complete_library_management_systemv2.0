<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];

// --- NEW PHP LOGIC: Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';


// Fetch all favorite books for the member
$sql = "
    SELECT 
        b.book_id,
        b.title,
        b.author,
        b.category,
        b.isbn,
        b.shelf_location,
        b.available_quantity,
        b.is_online_available,
        b.soft_copy_path,
        tf.added_on
    FROM 
        tbl_favorites tf
    JOIN 
        tbl_books b ON tf.book_id = b.book_id
    WHERE 
        tf.member_id = ?
    ORDER BY 
        tf.added_on DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$favorites_result = $stmt->get_result();

user_header('My Favourites');
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
        --success: #10b981;
        --danger: #ef233c;
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

    .favorites-container {
        max-width: 1200px;
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
        box-shadow: 0 10px 30px rgba(0,0,0,0.15); 
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
        color: var(--text-dark);
        margin-bottom: 20px;
        border-bottom: 2px solid rgba(0, 0, 0, 0.1);
        padding-bottom: 15px;
    }

    .custom-table {
        width: 100%;
        border-collapse: collapse;
    }

    .custom-table th {
        text-align: left;
        padding: 15px;
        color: var(--text-dark); 
        font-size: 0.85rem;
        text-transform: uppercase;
        font-weight: 700;
        border-bottom: 2px solid rgba(0, 0, 0, 0.15);
        background: rgba(67, 97, 238, 0.1); 
    }

    .custom-table td {
        padding: 15px;
        font-size: 0.95rem;
        border-bottom: 1px solid rgba(0, 0, 0, 0.08);
        color: var(--text-dark); /* Deep black data text */
    }

    .custom-table tr:last-child td { border-bottom: none; }
    .custom-table tr:hover { background: rgba(255, 255, 255, 0.9); }


    .no-favorites {
        text-align: center;
        padding: 40px;
        color: var(--text-light);
    }
    .no-favorites i {
        font-size: 3rem;
        margin-bottom: 15px;
        color: var(--danger);
    }
    .no-favorites h3 {
        font-weight: 600;
        color: var(--text-dark);
    }
    
    .status-pill {
        padding: 6px 15px;
        border-radius: 30px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
    }

    .status-available {
        background: #d1fae5; color: #059669;
    }

    .status-unavailable {
        background: #fee2e2; color: #dc2626;
    }
    
    .btn-remove {
        background: var(--danger);
        color: white;
        padding: 8px 15px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.3s;
        border: none;
        cursor: pointer;
        box-shadow: 0 2px 5px rgba(239, 35, 60, 0.3);
    }
    .btn-remove:hover {
        background: #c82333;
        transform: translateY(-1px);
    }
    
    .btn-read-online {
        background: var(--accent);
        color: white;
        padding: 8px 15px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none;
        transition: background 0.3s;
        display: inline-block;
        box-shadow: 0 2px 5px rgba(76, 201, 240, 0.3);
    }
    .btn-read-online:hover {
        background: #3faad1;
        transform: translateY(-1px);
    }

    /* --- Universal Modal Styles --- */
    .modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px);
        z-index: 3000; display: none; justify-content: center; align-items: center;
        opacity: 0; transition: opacity 0.3s ease;
    }
    
    .modal-overlay.active { display: flex; opacity: 1; }

    .modal-box {
        background: #fff;
        padding: 30px;
        border-radius: 20px;
        width: 90%;
        max-width: 400px;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        transform: scale(0.9); transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
    }
    
    .modal-overlay.active .modal-box { transform: scale(1); }

    .modal-icon {
        font-size: 3.5rem; margin-bottom: 15px; display: block;
    }
    
    .modal-title { margin: 0 0 10px 0; color: #111827; font-size: 1.5rem; font-weight: 700; }
    .modal-message { color: #4b5563; margin-bottom: 25px; line-height: 1.5; font-size: 1rem; }

    .modal-actions { display: flex; gap: 10px; justify-content: center; }
    
    .modal-btn {
        padding: 12px 24px; border-radius: 10px; border: none; font-weight: 600; cursor: pointer; transition: 0.2s;
        font-size: 1rem; flex: 1;
    }
    
    .btn-modal-confirm { background: var(--danger); color: white; box-shadow: 0 4px 12px rgba(239, 35, 60, 0.3); }
    .btn-modal-confirm:hover { background: #d90429; }
    
    .btn-modal-cancel { background: #f3f4f6; color: #4b5563; }
    .btn-modal-cancel:hover { background: #e5e7eb; }
    
    .btn-modal-ok { background: var(--primary); color: white; box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3); }
    .btn-modal-ok:hover { background: var(--secondary); }

</style>

<div class="favorites-container">
    <div class="card">
        <h1><i class="fas fa-heart" style="color: #ff7f50;"></i> My Favourites</h1>
        
        <?php if ($favorites_result->num_rows > 0): ?>
            <div style="overflow-x: auto;">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Book Title</th>
                            <th>Author</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Added On</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($book = $favorites_result->fetch_assoc()): 
                            $is_avail = $book['available_quantity'] > 0;
                        ?>
                            <tr id="favorite-row-<?php echo $book['book_id']; ?>">
                                <td>
                                    <div style="font-weight: 600; color: var(--text-dark);"><?php echo htmlspecialchars($book['title']); ?></div>
                                    <div style="font-size: 0.8rem; color: var(--text-light);">ISBN: <?php echo htmlspecialchars($book['isbn']); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($book['author']); ?></td>
                                <td><?php echo htmlspecialchars($book['category']); ?></td>
                                <td>
                                    <?php if ($is_avail): ?>
                                        <span class="status-pill status-available">Available (<?php echo $book['available_quantity']; ?>)</span>
                                    <?php else: ?>
                                        <span class="status-pill status-unavailable">Issued Out</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($book['added_on'])); ?></td>
                                <td>
                                    <?php if ($book['is_online_available'] == 1 && !empty($book['soft_copy_path'])): ?>
                                        <a href="read_online.php?book_id=<?php echo $book['book_id']; ?>" target="_blank" class="btn-read-online">
                                            <i class="fas fa-file-pdf"></i> Read
                                        </a>
                                    <?php endif; ?>
                                    <button class="btn-remove" onclick="confirmRemoveFavorite(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>')">
                                        <i class="fas fa-trash"></i> Remove
                                    </button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="no-favorites">
                <i class="fas fa-heart"></i>
                <h3>Your Favourites List is Empty</h3>
                <p>Start exploring the catalog and add books you love!</p>
                <a href="search.php" class="btn-read-online" style="margin-top: 15px;">
                    <i class="fas fa-search"></i> Search Catalog
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Generic Popup Modal -->
<div id="universalModal" class="modal-overlay">
    <div class="modal-box">
        <i id="modalIcon" class="fas fa-info-circle modal-icon"></i>
        <h3 id="modalTitle" class="modal-title">Message</h3>
        <p id="modalMessage" class="modal-message"></p>
        
        <div class="modal-actions" id="modalActions">
            <!-- Buttons will be injected here -->
        </div>
    </div>
</div>

<script>
    // Universal Modal Function
    function showModal(title, message, type = 'info', onConfirm = null) {
        const modal = document.getElementById('universalModal');
        const icon = document.getElementById('modalIcon');
        const titleEl = document.getElementById('modalTitle');
        const msgEl = document.getElementById('modalMessage');
        const actionsEl = document.getElementById('modalActions');
        
        titleEl.textContent = title;
        msgEl.innerHTML = message;
        actionsEl.innerHTML = ''; // Clear previous buttons

        // Set Icon & Color based on type
        if (type === 'success') {
            icon.className = 'fas fa-check-circle modal-icon';
            icon.style.color = '#10b981';
        } else if (type === 'error') {
            icon.className = 'fas fa-times-circle modal-icon';
            icon.style.color = '#ef4444';
        } else if (type === 'confirm') {
            icon.className = 'fas fa-question-circle modal-icon';
            icon.style.color = '#f59e0b';
        } else {
            icon.className = 'fas fa-info-circle modal-icon';
            icon.style.color = '#3b82f6';
        }

        // Buttons Logic
        if (type === 'confirm') {
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'modal-btn btn-modal-cancel';
            cancelBtn.textContent = 'Cancel';
            cancelBtn.onclick = closeModal;
            
            const confirmBtn = document.createElement('button');
            confirmBtn.className = 'modal-btn btn-modal-confirm';
            confirmBtn.textContent = 'Yes, Remove';
            confirmBtn.onclick = () => {
                if (onConfirm) onConfirm();
                closeModal();
            };
            
            actionsEl.appendChild(cancelBtn);
            actionsEl.appendChild(confirmBtn);
        } else {
            const okBtn = document.createElement('button');
            okBtn.className = 'modal-btn btn-modal-ok';
            okBtn.textContent = 'OK';
            okBtn.onclick = closeModal;
            actionsEl.appendChild(okBtn);
        }

        modal.classList.add('active');
    }

    function closeModal() {
        document.getElementById('universalModal').classList.remove('active');
    }

    // Close modal on outside click
    document.getElementById('universalModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    // --- Specific Actions ---

    function confirmRemoveFavorite(bookId, bookTitle) {
        showModal(
            'Remove Favorite?', 
            `Are you sure you want to remove <strong>"${bookTitle}"</strong> from your favorites list?`, 
            'confirm', 
            () => processRemoval(bookId)
        );
    }

    function processRemoval(bookId) {
        fetch('favorites_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=remove&book_id=${bookId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById(`favorite-row-${bookId}`);
                if (row) {
                    row.style.transition = 'opacity 0.5s';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 500);
                }
                
                // Check if empty and reload if needed to show "No favorites" message
                const tbody = document.querySelector('.custom-table tbody');
                if (tbody && tbody.children.length <= 1) { // 1 because row removal happens after timeout
                     setTimeout(() => window.location.reload(), 600);
                } else {
                    showModal('Success', 'Book removed from favorites.', 'success');
                }
            } else {
                showModal('Error', 'Failed to remove favorite: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showModal('Error', 'An unexpected error occurred.', 'error');
        });
    }
</script>

<?php
user_footer();
close_db_connection($conn);
?>