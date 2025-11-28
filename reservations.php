<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];

// --- NEW PHP LOGIC: Get Logo Path for Watermark ---
$bg_logo_path = get_setting($conn, 'institution_logo');
$full_bg_logo_path = (!empty($bg_logo_path) && file_exists($bg_logo_path)) ? $bg_logo_path : '';

// --- Handle Cancellation ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_res_id'])) {
    $res_id = (int)$_POST['cancel_res_id'];
    // Update status AND cancelled_by column
    $stmt = $conn->prepare("UPDATE tbl_reservations SET status = 'Cancelled', cancelled_by = 'Member' WHERE reservation_id = ? AND member_id = ? AND status = 'Pending'");
    $stmt->bind_param("ii", $res_id, $member_id);
    $stmt->execute();
}

// --- Fetch Reservations ---
$sql = "SELECT tr.*, tb.title, tb.author 
        FROM tbl_reservations tr 
        JOIN tbl_books tb ON tr.book_id = tb.book_id 
        WHERE tr.member_id = ? 
        ORDER BY tr.reservation_date DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $member_id);
$stmt->execute();
$result = $stmt->get_result();

user_header('My Reservations');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* --- SHARED VARIABLES (MATCHING DASHBOARD/INDEX) --- */
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

    /* --- BODY STYLES (Dark Gradient & Watermark) --- */
    body {
        font-family: 'Poppins', sans-serif;
        color: var(--text-dark);
        background: radial-gradient(at 0% 0%, hsla(253,16%,7%,1) 0, transparent 50%), 
                    radial-gradient(at 50% 100%, hsla(225,39%,25%,1) 0, transparent 50%), 
                    radial-gradient(at 100% 0%, hsla(339,49%,25%,1) 0, transparent 50%),
                    #0f172a;
        background-attachment: fixed; 
        background-size: cover;
        position: relative; 
    }
    
    /* Watermark CSS */
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

    .res-container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
    
    /* --- GLASS CARD EFFECT --- */
    .card { 
        background: var(--card-bg); 
        backdrop-filter: blur(15px); 
        -webkit-backdrop-filter: blur(15px);
        border-radius: 20px; 
        padding: 30px; 
        box-shadow: var(--shadow); 
        border: 1px solid rgba(255, 255, 255, 0.8);
        color: var(--text-dark); /* Ensure card text is deep black */
        animation: fadeIn 0.5s ease-out;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .card h1 { 
        font-family: 'Poppins', sans-serif; 
        font-size: 2rem; 
        margin-bottom: 20px; 
        color: var(--text-dark); 
        border-bottom: 2px solid rgba(0, 0, 0, 0.1); /* Darker divider */
        padding-bottom: 15px; 
    }
    
    /* --- TABLE STYLES --- */
    .res-table { width: 100%; border-collapse: collapse; }
    
    .res-table th { 
        text-align: left; 
        padding: 15px; 
        color: var(--text-light); /* Darker muted header text */
        text-transform: uppercase; 
        font-size: 0.85rem; 
        font-weight: 700;
        border-bottom: 2px solid rgba(0, 0, 0, 0.15); /* Stronger separator */
        background: rgba(67, 97, 238, 0.1); /* Subtle primary color background */
        color: var(--text-dark); /* Deep Black Header Text */
    }
    
    .res-table td { 
        padding: 15px; 
        border-bottom: 1px solid rgba(0, 0, 0, 0.08); /* Subtle separator */
        color: var(--text-dark); /* Deep Black data text */
    }
    
    .res-table tr:hover { 
        background: rgba(255, 255, 255, 0.9); /* Opaque white on hover */
    }

    .status-badge { 
        padding: 6px 12px; 
        border-radius: 20px; 
        font-size: 0.8rem; 
        font-weight: 700; 
        text-transform: uppercase; 
    }
    
    /* Status Colors (Adjusted for contrast) */
    .st-Pending { background: #fef3c7; color: #92400e; }
    .st-Accepted { background: #d1fae5; color: #065f46; }
    .st-Rejected { background: #fee2e2; color: #991b1b; }
    .st-Fulfilled { background: #e0e7ff; color: #3730a3; }
    .st-Cancelled { background: #e5e7eb; color: #4b5563; }
    
    .btn-cancel { 
        background: var(--danger); 
        color: white; 
        border: none; 
        padding: 8px 15px; 
        border-radius: 6px; 
        cursor: pointer; 
        font-weight: 600; 
        font-size: 0.85rem; 
        transition: background 0.3s;
        box-shadow: 0 2px 5px rgba(239, 35, 60, 0.3);
    }
    .btn-cancel:hover { 
        background: #d90429; 
    }
    
    .btn-qr {
        background: transparent;
        border: none;
        color: var(--primary);
        cursor: pointer;
        font-size: 1.2rem;
        padding: 5px;
        transition: transform 0.2s;
    }
    .btn-qr:hover {
        transform: scale(1.2);
        color: var(--secondary);
    }
    
    /* No Reservations message color adjustment */
    .card p {
        color: var(--text-dark);
    }

    /* --- POPUP MODAL STYLES --- */
    .modal-overlay {
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(5px);
        z-index: 2000; display: none; justify-content: center; align-items: center;
        animation: fadeInModal 0.3s ease;
    }
    
    .modal-box {
        background: var(--white);
        width: 90%; max-width: 400px;
        border-radius: 16px; padding: 30px;
        text-align: center;
        box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        transform: scale(0.9);
        animation: scaleUp 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    }

    .modal-icon {
        width: 60px; height: 60px;
        background: #fee2e2; color: var(--danger);
        border-radius: 50%; display: flex; justify-content: center; align-items: center;
        font-size: 1.8rem; margin: 0 auto 20px;
    }

    .modal-title {
        font-size: 1.4rem; font-weight: 700; color: var(--text-dark); margin-bottom: 10px;
    }

    .modal-desc {
        color: var(--text-light); margin-bottom: 25px; font-size: 0.95rem; line-height: 1.5;
    }

    .modal-actions {
        display: flex; gap: 10px; justify-content: center;
    }

    .btn-modal {
        padding: 10px 20px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer;
        font-size: 0.9rem; flex: 1;
    }

    .btn-modal-cancel {
        background: #f3f4f6; color: #4b5563;
    }
    .btn-modal-cancel:hover { background: #e5e7eb; }

    .btn-modal-confirm {
        background: var(--danger); color: white;
    }
    .btn-modal-confirm:hover { background: #d90429; }

    @keyframes fadeInModal { from { opacity: 0; } to { opacity: 1; } }
    @keyframes scaleUp { to { transform: scale(1); } }
    
</style>

<div class="res-container">
    <div class="card">
        <h1><i class="fas fa-calendar-check" style="color: #4361ee;"></i> Reservation History</h1>
        
        <?php if ($result->num_rows > 0): ?>
            <div style="overflow-x: auto;">
                <table class="res-table">
                    <thead>
                        <tr>
                            <th>Reservation ID</th>
                            <th>Book Title</th>
                            <th>Book ID</th> 
                            <th>Date</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td style="font-weight: 600; color: #2b2d42;">
                                    <?php echo $row['reservation_uid']; ?>
                                    <button class="btn-qr" onclick="openQrModal('<?php echo $row['reservation_uid']; ?>')" title="View QR Code">
                                        <i class="fas fa-qrcode"></i>
                                    </button>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?php echo $row['title']; ?></div>
                                    <div style="font-size: 0.85rem; color: #8d99ae;"><?php echo $row['author']; ?></div>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-weight: 600; color: #4b5563; background: #e9ecef; padding: 4px 8px; border-radius: 4px;">
                                        <?php echo htmlspecialchars($row['book_base_uid'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($row['reservation_date'])); ?></td>
                                <td>
                                    <span class="status-badge st-<?php echo $row['status']; ?>"><?php echo $row['status']; ?></span>
                                    <?php if($row['status'] === 'Cancelled' && !empty($row['cancelled_by'])): ?>
                                        <div style="font-size: 0.75rem; color: #999; margin-top: 4px;">
                                            by <?php echo htmlspecialchars($row['cancelled_by']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['status'] === 'Pending'): ?>
                                        <button type="button" class="btn-cancel" onclick="openCancelModal(<?php echo $row['reservation_id']; ?>)">
                                            <i class="fas fa-times"></i> Cancel
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #ccc;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 40px; color: #8d99ae;">You have no reservations.</p>
        <?php endif; ?>
    </div>
</div>

<div id="qrModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-icon" style="background: #e0f2fe; color: #0284c7;">
            <i class="fas fa-qrcode"></i>
        </div>
        <div class="modal-title">Reservation QR Code</div>
        <div id="qrContainer" style="display: flex; justify-content: center; margin: 20px 0; padding: 10px; background: white; border-radius: 10px;"></div>
        
        <div class="modal-desc" id="qrText" style="font-family: monospace; font-weight: bold; font-size: 1.1rem; color: #333;"></div>
        
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeQrModal()">Close</button>
        </div>
    </div>
</div>

<div id="cancelModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="modal-title">Cancel Reservation?</div>
        <div class="modal-desc">
            Are you sure you want to cancel this reservation request? This action cannot be undone.
        </div>
        <div class="modal-actions">
            <button type="button" class="btn-modal btn-modal-cancel" onclick="closeCancelModal()">Keep it</button>
            <button type="button" class="btn-modal btn-modal-confirm" onclick="confirmCancel()">Yes, Cancel</button>
        </div>
    </div>
</div>

<form id="cancelForm" method="POST" style="display: none;">
    <input type="hidden" name="cancel_res_id" id="cancelResIdInput">
</form>

<script>
    // --- 4. QR Code Logic ---
    function openQrModal(uid) {
        document.getElementById('qrModal').style.display = 'flex';
        document.getElementById('qrText').innerText = uid;
        
        const container = document.getElementById('qrContainer');
        container.innerHTML = ''; // Clear previous
        
        // Generate QR Code
        new QRCode(container, {
            text: uid,
            width: 180,
            height: 180,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.H
        });
    }

    function closeQrModal() {
        document.getElementById('qrModal').style.display = 'none';
    }

    // --- Cancellation Logic ---
    let currentResId = null;

    function openCancelModal(id) {
        currentResId = id;
        document.getElementById('cancelModal').style.display = 'flex';
    }

    function closeCancelModal() {
        document.getElementById('cancelModal').style.display = 'none';
        currentResId = null;
    }

    function confirmCancel() {
        if (currentResId) {
            document.getElementById('cancelResIdInput').value = currentResId;
            document.getElementById('cancelForm').submit();
        }
    }

    // Close modal if clicked outside box
    window.onclick = function(event) {
        const cancelModal = document.getElementById('cancelModal');
        const qrModal = document.getElementById('qrModal');
        if (event.target == cancelModal) {
            closeCancelModal();
        }
        if (event.target == qrModal) {
            closeQrModal();
        }
    }
</script>

<?php user_footer(); close_db_connection($conn); ?>