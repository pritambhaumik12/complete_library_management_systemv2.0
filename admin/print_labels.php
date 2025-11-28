<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$is_super = is_super_admin($conn);
$admin_id = $_SESSION['admin_id'];

// --- 1. Fetch Libraries (For Dropdown) ---
$libraries = [];
if ($is_super) {
    $lib_res = $conn->query("SELECT library_id, library_name FROM tbl_libraries ORDER BY library_name ASC");
    while($row = $lib_res->fetch_assoc()) {
        $libraries[] = $row;
    }
} else {
    // Fetch logged-in admin's library
    $stmt = $conn->prepare("SELECT l.library_id, l.library_name FROM tbl_admin a JOIN tbl_libraries l ON a.library_id = l.library_id WHERE a.admin_id = ?");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $libraries[] = $row;
    }
}

// --- 2. AJAX Handler: Fetch Books by Library (For 'Selected' Mode) ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_books_by_lib') {
    $lib_id = (int)$_GET['library_id'];
    
    // Fetch only physical books (Physical or Both)
    $sql = "SELECT b.book_id, b.title, b.author, b.total_quantity, tbc.book_uid as base_uid
            FROM tbl_books b
            LEFT JOIN tbl_book_copies tbc ON b.book_id = tbc.book_id AND tbc.book_uid LIKE '%-BASE'
            WHERE b.library_id = ? AND b.total_quantity > 0 
            ORDER BY b.title ASC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $lib_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $books = [];
    while($row = $res->fetch_assoc()) {
        // Clean up base UID display
        $row['base_uid'] = $row['base_uid'] ? substr($row['base_uid'], 0, -5) : 'N/A';
        $books[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($books);
    exit;
}

// --- 3. AJAX Handler: Suggest Books (For 'One Book' Mode) ---
if (isset($_GET['action']) && $_GET['action'] === 'suggest_books') {
    $query = trim($_GET['query']);
    $lib_id = (int)$_GET['library_id'];
    
    if (strlen($query) < 2) { echo json_encode([]); exit; }

    $search_term = "%$query%";
    
    // Search in Title, Author OR Base UID
    $sql = "SELECT b.book_id, b.title, b.author, b.edition, b.publication, b.isbn, b.total_quantity, tbc.book_uid as base_uid
            FROM tbl_books b
            LEFT JOIN tbl_book_copies tbc ON b.book_id = tbc.book_id AND tbc.book_uid LIKE '%-BASE'
            WHERE b.library_id = ? AND b.total_quantity > 0 
            AND (b.title LIKE ? OR b.author LIKE ? OR tbc.book_uid LIKE ?)
            LIMIT 10";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isss", $lib_id, $search_term, $search_term, $search_term);
    $stmt->execute();
    $res = $stmt->get_result();
    
    $suggestions = [];
    while($row = $res->fetch_assoc()) {
        $row['base_uid_clean'] = $row['base_uid'] ? substr($row['base_uid'], 0, -5) : 'N/A';
        $suggestions[] = $row;
    }
    
    header('Content-Type: application/json');
    echo json_encode($suggestions);
    exit;
}

admin_header('Print Book Labels');
?>

<style>
    /* Existing Styles */
    .glass-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(15px);
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        margin-bottom: 30px;
    }
    .step-box { margin-bottom: 20px; }
    .step-title { font-weight: 700; color: #4f46e5; margin-bottom: 10px; text-transform: uppercase; font-size: 0.9rem; }
    .form-control { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; margin-bottom: 10px; }
    .option-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; }
    .radio-card {
        border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px; cursor: pointer; transition: 0.2s;
        display: flex; align-items: center; gap: 10px; background: #fff;
    }
    .radio-card:hover { background: #f8fafc; border-color: #4f46e5; }
    .radio-card input { accent-color: #4f46e5; transform: scale(1.2); }
    .btn-main { background: #4f46e5; color: white; border: none; padding: 12px 25px; border-radius: 8px; font-weight: 600; cursor: pointer; width: 100%; transition: 0.2s; }
    .btn-main:hover { background: #4338ca; }
    
    /* Autocomplete Styles */
    .suggestion-container { position: relative; }
    .suggestions-list {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;
        background: white; border: 1px solid #cbd5e1; border-top: none;
        border-radius: 0 0 8px 8px; max-height: 250px; overflow-y: auto;
        box-shadow: 0 10px 15px rgba(0,0,0,0.1); display: none;
    }
    .suggestion-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
    .suggestion-item:hover { background: #eef2ff; color: #4f46e5; }
    .suggestion-item strong { color: #1e293b; display: block; }
    .suggestion-item small { color: #64748b; font-family: monospace; }

    /* Table & Summary */
    .book-table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 0.9rem; }
    .book-table th { background: #e0e7ff; color: #3730a3; padding: 10px; text-align: left; }
    .book-table td { border-bottom: 1px solid #e2e8f0; padding: 10px; }
    .book-table tr:hover { background: #f1f5f9; }
    .summary-box { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; padding: 15px; border-radius: 10px; margin-top: 20px; display: none; }
</style>

<div class="glass-card">
    <h2 style="margin-top:0;"><i class="fas fa-print"></i> Print Book Labels</h2>
    <p style="color:#64748b;">Generate QR Codes or Barcodes for physical book copies.</p>

    <form action="print_labels_output.php" method="POST" target="_blank" id="printForm">
        
        <div class="step-box">
            <div class="step-title">1. Select Library</div>
            <select name="library_id" id="library_id" class="form-control" required onchange="resetSections()">
                <?php foreach($libraries as $lib): ?>
                    <option value="<?php echo $lib['library_id']; ?>"><?php echo htmlspecialchars($lib['library_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="step-box">
            <div class="step-title">2. Label Type</div>
            <div class="option-grid">
                <label class="radio-card">
                    <input type="radio" name="label_type" value="qrcode" checked>
                    <div><i class="fas fa-qrcode"></i> QR Code</div>
                </label>
                <label class="radio-card">
                    <input type="radio" name="label_type" value="barcode">
                    <div><i class="fas fa-barcode"></i> Barcode</div>
                </label>
            </div>
        </div>

        <div class="step-box">
            <div class="step-title">3. Selection Mode</div>
            <div class="option-grid">
                <label class="radio-card">
                    <input type="radio" name="mode" value="all" onclick="toggleMode('all')">
                    <span>All Library Books</span>
                </label>
                <label class="radio-card">
                    <input type="radio" name="mode" value="selected" onclick="toggleMode('selected')">
                    <span>Select Specific Books</span>
                </label>
                <label class="radio-card">
                    <input type="radio" name="mode" value="one" onclick="toggleMode('one')">
                    <span>Single Book</span>
                </label>
            </div>
        </div>

        <div id="section-all" class="dynamic-section" style="display:none; background:#eef2ff; padding:15px; border-radius:10px; margin-bottom:20px;">
            <i class="fas fa-info-circle"></i> This will generate labels for <strong>ALL physical copies</strong> in the selected library.
        </div>

        <div id="section-selected" class="dynamic-section" style="display:none;">
            <button type="button" class="btn-main" onclick="loadLibraryBooks()" style="background:#6366f1; margin-bottom:15px;">
                <i class="fas fa-list"></i> Load Books from Library
            </button>
            
            <div id="booksListContainer" style="max-height:400px; overflow-y:auto; display:none; border:1px solid #e2e8f0; border-radius:10px;">
                <table class="book-table">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" onclick="toggleAll(this)"></th>
                            <th>Base ID</th>
                            <th>Title</th>
                            <th>Author</th>
                            <th>Copies</th>
                        </tr>
                    </thead>
                    <tbody id="booksTableBody"></tbody>
                </table>
            </div>
            
            <button type="button" id="btnPreviewSelected" class="btn-main" onclick="previewSelection()" style="display:none; margin-top:15px; background:#059669;">
                View Final List
            </button>

            <div id="selectionSummary" class="summary-box">
                <h4><i class="fas fa-clipboard-check"></i> Final Selection</h4>
                <ul id="summaryList" style="padding-left:20px; margin-bottom:0;"></ul>
                <div style="margin-top:10px; font-weight:bold;" id="summaryCount"></div>
            </div>
        </div>

        <div id="section-one" class="dynamic-section" style="display:none;">
            <div class="suggestion-container">
                <input type="text" id="search_one_book" class="form-control" placeholder="Type Title, Author, or Book ID to search..." autocomplete="off" oninput="fetchSuggestions(this)">
                <div id="one_book_suggestions" class="suggestions-list"></div>
            </div>
            
            <div id="oneBookDetails" style="display:none; background:#f8fafc; padding:15px; border-radius:10px; margin-top:15px; border:1px solid #e2e8f0;">
                <input type="hidden" name="one_book_id" id="one_book_id_input">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <h3 id="ob_title" style="margin:0 0 5px 0; color:#4f46e5;"></h3>
                    <button type="button" onclick="resetOneBook()" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:0.9rem;">Change</button>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; font-size:0.9rem; color:#475569; margin-top:10px;">
                    <div><strong>ID (Base):</strong> <span id="ob_uid" style="font-family:monospace;"></span></div>
                    <div><strong>Author:</strong> <span id="ob_author"></span></div>
                    <div><strong>Publisher:</strong> <span id="ob_pub"></span></div>
                    <div><strong>Edition:</strong> <span id="ob_ed"></span></div>
                    <div><strong>ISBN:</strong> <span id="ob_isbn"></span></div>
                    <div><strong>Total Copies:</strong> <span id="ob_qty"></span></div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-main" id="printBtn" style="margin-top:20px; display:none;">
            <i class="fas fa-print"></i> Generate Labels
        </button>

    </form>
</div>

<script>
function resetSections() {
    document.querySelectorAll('.dynamic-section').forEach(el => el.style.display = 'none');
    document.querySelectorAll('input[name="mode"]').forEach(el => el.checked = false);
    document.getElementById('printBtn').style.display = 'none';
    // Reset data
    document.getElementById('booksTableBody').innerHTML = '';
    document.getElementById('booksListContainer').style.display = 'none';
    document.getElementById('selectionSummary').style.display = 'none';
    resetOneBook();
}

function toggleMode(mode) {
    document.querySelectorAll('.dynamic-section').forEach(el => el.style.display = 'none');
    document.getElementById('section-' + mode).style.display = 'block';
    
    const printBtn = document.getElementById('printBtn');
    if (mode === 'all') {
        printBtn.style.display = 'block';
        printBtn.innerHTML = '<i class="fas fa-print"></i> Print All Library Labels';
    } else if (mode === 'selected') {
        printBtn.style.display = 'none'; 
    } else if (mode === 'one') {
        printBtn.style.display = 'none'; 
    }
}

// --- LOGIC: SELECTED BOOKS ---
function loadLibraryBooks() {
    const libId = document.getElementById('library_id').value;
    const tbody = document.getElementById('booksTableBody');
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';
    document.getElementById('booksListContainer').style.display = 'block';

    fetch(`print_labels.php?action=fetch_books_by_lib&library_id=${libId}`)
        .then(r => r.json())
        .then(data => {
            tbody.innerHTML = '';
            if(data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">No physical books found in this library.</td></tr>';
                return;
            }
            data.forEach(b => {
                const tr = `
                    <tr>
                        <td><input type="checkbox" name="selected_book_ids[]" value="${b.book_id}" data-title="${b.title}" data-qty="${b.total_quantity}"></td>
                        <td style="font-family:monospace; color:#4f46e5;">${b.base_uid}</td>
                        <td>${b.title}</td>
                        <td>${b.author}</td>
                        <td>${b.total_quantity}</td>
                    </tr>
                `;
                tbody.innerHTML += tr;
            });
            document.getElementById('btnPreviewSelected').style.display = 'block';
        });
}

function toggleAll(source) {
    document.querySelectorAll('input[name="selected_book_ids[]"]').forEach(cb => cb.checked = source.checked);
}

function previewSelection() {
    const checkboxes = document.querySelectorAll('input[name="selected_book_ids[]"]:checked');
    const list = document.getElementById('summaryList');
    const summaryBox = document.getElementById('selectionSummary');
    const countDiv = document.getElementById('summaryCount');
    const printBtn = document.getElementById('printBtn');

    list.innerHTML = '';
    let totalCopies = 0;

    if (checkboxes.length === 0) {
        alert("Please select at least one book.");
        return;
    }

    checkboxes.forEach(cb => {
        const title = cb.getAttribute('data-title');
        const qty = parseInt(cb.getAttribute('data-qty'));
        totalCopies += qty;
        const li = document.createElement('li');
        li.innerHTML = `<strong>${title}</strong> (${qty} copies)`;
        list.appendChild(li);
    });

    countDiv.innerHTML = `Total Labels to Print: ${totalCopies}`;
    summaryBox.style.display = 'block';
    
    printBtn.style.display = 'block';
    printBtn.innerHTML = '<i class="fas fa-print"></i> Print Selected Labels';
}

// --- LOGIC: ONE BOOK (AUTOCOMPLETE) ---
let debounceTimer;

function fetchSuggestions(input) {
    const query = input.value.trim();
    const libId = document.getElementById('library_id').value;
    const list = document.getElementById('one_book_suggestions');

    if (query.length < 2) {
        list.style.display = 'none';
        return;
    }

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        fetch(`print_labels.php?action=suggest_books&library_id=${libId}&query=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                list.innerHTML = '';
                if (data.length > 0) {
                    data.forEach(b => {
                        const div = document.createElement('div');
                        div.className = 'suggestion-item';
                        div.innerHTML = `
                            <strong>${b.title}</strong>
                            <small>${b.author} | ID: ${b.base_uid_clean}</small>
                        `;
                        div.onclick = () => selectOneBook(b);
                        list.appendChild(div);
                    });
                    list.style.display = 'block';
                } else {
                    list.style.display = 'none';
                }
            });
    }, 300);
}

function selectOneBook(book) {
    // Hide search, show details
    document.getElementById('one_book_suggestions').style.display = 'none';
    document.getElementById('search_one_book').value = ''; // Clear input
    document.getElementById('search_one_book').parentElement.style.display = 'none';

    // Fill details
    document.getElementById('one_book_id_input').value = book.book_id;
    document.getElementById('ob_title').textContent = book.title;
    document.getElementById('ob_uid').textContent = book.base_uid_clean;
    document.getElementById('ob_author').textContent = book.author;
    document.getElementById('ob_pub').textContent = book.publication || '-';
    document.getElementById('ob_ed').textContent = book.edition || '-';
    document.getElementById('ob_isbn').textContent = book.isbn || '-';
    document.getElementById('ob_qty').textContent = book.total_quantity;

    document.getElementById('oneBookDetails').style.display = 'block';
    
    // Show Print Button
    const printBtn = document.getElementById('printBtn');
    printBtn.style.display = 'block';
    printBtn.innerHTML = `<i class="fas fa-print"></i> Print Labels for "${book.title}"`;
}

function resetOneBook() {
    document.getElementById('oneBookDetails').style.display = 'none';
    document.getElementById('search_one_book').parentElement.style.display = 'block';
    document.getElementById('printBtn').style.display = 'none';
    document.getElementById('one_book_id_input').value = '';
}

// Hide suggestions on click outside
document.addEventListener('click', (e) => {
    if (!e.target.closest('.suggestion-container')) {
        document.getElementById('one_book_suggestions').style.display = 'none';
    }
});
</script>

<?php admin_footer(); close_db_connection($conn); ?>