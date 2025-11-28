<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';
$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_full_name'] ?? 'Admin';
$currency = get_setting($conn, 'currency_symbol');

// --- AJAX Endpoint to Fetch Clearance Status ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_status') {
    header('Content-Type: application/json');
    $member_uid = trim($_GET['member_uid'] ?? '');
    $status_data = ['success' => false, 'message' => 'Member not found.', 'cleared' => false];

    if (!empty($member_uid)) {
        // 1. Fetch Member Details
        $sql_member = "SELECT member_id, full_name, member_uid, email, department FROM tbl_members WHERE member_uid = ? AND status = 'Active'";
        $stmt_member = $conn->prepare($sql_member);
        $stmt_member->bind_param("s", $member_uid);
        $stmt_member->execute();
        $member_info = $stmt_member->get_result()->fetch_assoc();

        if ($member_info) {
            $member_id = $member_info['member_id'];
            $status_data['member_details'] = $member_info;
            $status_data['success'] = true;

            // 2. Check for Issued Books (Global Check: Issued OR Overdue)
            $sql_issued = "
                SELECT COUNT(*) as count 
                FROM tbl_circulation tc 
                JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id 
                JOIN tbl_books tb ON tbc.book_id = tb.book_id 
                WHERE tc.member_id = ? AND tc.status IN ('Issued', 'Overdue')
            ";
            $stmt_issued = $conn->prepare($sql_issued);
            $stmt_issued->bind_param("i", $member_id);
            $stmt_issued->execute();
            $issued_count = $stmt_issued->get_result()->fetch_assoc()['count'];
            $status_data['issued_count'] = (int)$issued_count;

            // 3. Check for Outstanding Fines (Global Check)
            $sql_fines = "SELECT SUM(fine_amount) as total FROM tbl_fines WHERE member_id = ? AND payment_status = 'Pending'";
            $stmt_fines = $conn->prepare($sql_fines);
            $stmt_fines->bind_param("i", $member_id);
            $stmt_fines->execute();
            $total_fines = $stmt_fines->get_result()->fetch_assoc()['total'] ?? 0;
            $status_data['total_fines'] = (float)$total_fines;

            // 4. Check for Active Reservations (Global Check)
            $sql_res = "SELECT COUNT(*) as count FROM tbl_reservations WHERE member_id = ? AND status IN ('Pending', 'Accepted')";
            $stmt_res = $conn->prepare($sql_res);
            $stmt_res->bind_param("i", $member_id);
            $stmt_res->execute();
            $res_count = $stmt_res->get_result()->fetch_assoc()['count'];
            $status_data['reservation_count'] = (int)$res_count;

            // 5. Determine Clearance Status
            if ($issued_count == 0 && $total_fines == 0 && $res_count == 0) {
                $status_data['cleared'] = true;
                $status_data['message'] = "Member is clear for Library Clearance.";
            } else {
                $status_data['cleared'] = false;
                $status_data['message'] = "Member has outstanding dues or active items.";
                
                // Get detailed lists if not cleared
                if ($issued_count > 0) {
                    $sql_issued_list = "
                        SELECT tb.title, tbc.book_uid, tc.due_date, tc.status
                        FROM tbl_circulation tc 
                        JOIN tbl_book_copies tbc ON tc.copy_id = tbc.copy_id 
                        JOIN tbl_books tb ON tbc.book_id = tb.book_id 
                        WHERE tc.member_id = ? AND tc.status IN ('Issued', 'Overdue')
                    ";
                    $stmt_issued_list = $conn->prepare($sql_issued_list);
                    $stmt_issued_list->bind_param("i", $member_id);
                    $stmt_issued_list->execute();
                    $status_data['issued_list'] = $stmt_issued_list->get_result()->fetch_all(MYSQLI_ASSOC);
                }

                if ($total_fines > 0) {
                     $sql_fine_list = "SELECT fine_type, fine_amount FROM tbl_fines WHERE member_id = ? AND payment_status = 'Pending'";
                     $stmt_fine_list = $conn->prepare($sql_fine_list);
                     $stmt_fine_list->bind_param("i", $member_id);
                     $stmt_fine_list->execute();
                     $status_data['fine_list'] = $stmt_fine_list->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                
                if ($res_count > 0) {
                     $sql_res_list = "SELECT tb.title, tr.status FROM tbl_reservations tr JOIN tbl_books tb ON tr.book_id = tb.book_id WHERE tr.member_id = ? AND tr.status IN ('Pending', 'Accepted')";
                     $stmt_res_list = $conn->prepare($sql_res_list);
                     $stmt_res_list->bind_param("i", $member_id);
                     $stmt_res_list->execute();
                     $status_data['res_list'] = $stmt_res_list->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            }
        }
    }

    echo json_encode($status_data);
    close_db_connection($conn);
    exit;
}

admin_header('Library Clearance');
?>

<script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>

<style>
    /* Glass Components */
    .glass-card {
        background: rgba(255, 255, 255, 0.7); 
        backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255, 255, 255, 0.7);
        box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.1); 
        border-radius: 20px; padding: 30px; margin-bottom: 30px;
        max-width: 900px; margin: 0 auto;
        color: #111827;
        animation: fadeIn 0.5s ease-out;
    }
    .card-header h2 { color: #000; font-size: 1.8rem; display: flex; align-items: center; gap: 10px; }

    /* Input & Suggestions */
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: #111827; margin-bottom: 8px; }
    .input-and-suggestions { position: relative; width: 100%; }
    .input-wrapper { position: relative; }
    .form-control { width: 90%; padding: 14px 14px 14px 45px; border: 1px solid #d1d5db; background: rgba(255, 255, 255, 0.9); border-radius: 12px; font-size: 1rem; color: #111827; transition: all 0.3s; }
    .form-control:focus { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1); outline: none; }
    .input-wrapper i.fa-user-shield { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #6b7280; }
    
    /* Auto Complete List */
    .autocomplete-list { position: absolute; width: 90%; max-height: 200px; overflow-y: auto; background: white; border: 1px solid #d1d5db; border-top: none; border-radius: 0 0 12px 12px; box-shadow: 0 8px 15px rgba(0,0,0,0.1); z-index: 100; left: 0; top: 100%; }
    .autocomplete-item { padding: 10px 15px; cursor: pointer; font-size: 0.95rem; color: #111827; font-family: monospace; }
    .autocomplete-item:hover { background: #e0e7ff; color: #4f46e5; }
    
    /* Scanner Button */
    .btn-scan {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        background: transparent;
        border: none;
        color: #4f46e5;
        cursor: pointer;
        font-size: 1.2rem;
        padding: 5px;
        z-index: 10;
        transition: transform 0.2s;
    }
    .btn-scan:hover { color: #3730a3; transform: translateY(-50%) scale(1.1); }

    /* Member Status Display */
    #memberStatusCard {
        border-radius: 16px;
        padding: 25px;
        background: rgba(243, 244, 246, 0.8);
        border: 1px solid #e5e7eb;
        margin-top: 30px;
        display: none;
    }
    .status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    .status-item { margin-bottom: 15px; }
    .status-item label { font-size: 0.9rem; color: #6b7280; font-weight: 500; display: block; margin-bottom: 5px; }
    .status-item span { font-size: 1.1rem; font-weight: 700; color: #111827; }

    .clear-status-bar {
        text-align: center;
        padding: 20px;
        border-radius: 12px;
        margin-top: 25px;
        font-size: 1.2rem;
        font-weight: 700;
        border: 2px solid transparent;
    }
    .status-cleared { background: #d1fae5; color: #065f46; border-color: #065f46; }
    .status-unclear { background: #fee2e2; color: #991b1b; border-color: #991b1b; }

    .btn-generate {
        background: #059669;
        color: white;
        border: none;
        padding: 15px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 1rem;
        cursor: pointer;
        width: 100%;
        margin-top: 20px;
        transition: background 0.3s;
    }
    .btn-generate:hover:not(:disabled) { background: #047857; }
    .btn-generate:disabled { background: #9ca3af; cursor: not-allowed; }

    /* Detail Tables */
    .detail-list h4 { font-size: 1rem; color: #4f46e5; border-bottom: 1px solid #e2e8f0; padding-bottom: 5px; margin-bottom: 10px; margin-top: 20px; }
    .detail-list ul { list-style: none; padding: 0; }
    .detail-list li { padding: 8px 0; border-bottom: 1px dashed #e2e8f0; font-size: 0.95rem; }
    .detail-list .list-danger { color: #991b1b; font-weight: 600; }
    .detail-list .list-warning { color: #f59e0b; font-weight: 600; }
    .detail-list .list-info { color: #3b82f6; }

    /* Buttons */
    .btn-main {
        background: linear-gradient(135deg, #4f46e5, #3730a3); color: white; border: none;
        padding: 15px; border-radius: 12px; font-weight: 700; font-size: 1rem;
        cursor: pointer; transition: transform 0.2s;
        display: inline-flex; align-items: center; justify-content: center; gap: 10px;
    }
    .btn-main:hover { transform: translateY(-2px); }

    /* Scanner Modal Styles */
    #scannerModal {
        display: none;
        position: fixed;
        z-index: 4000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.8);
        justify-content: center;
        align-items: center;
    }
    #scanner-container {
        background: white;
        width: 90%;
        max-width: 500px;
        border-radius: 16px;
        padding: 20px;
        position: relative;
        text-align: center;
    }
    #reader { width: 100%; margin-bottom: 15px; border-radius: 12px; overflow: hidden; }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-certificate" style="color: #059669;"></i> Generate Library Clearance</h2>
    </div>

    <form id="memberForm" onsubmit="return false;">
        <div class="form-group">
            <label>Member ID (UID)</label>
            <div class="input-and-suggestions">
                <div class="input-wrapper">
                    <i class="fas fa-user-shield"></i>
                    <input type="text" id="member_uid_input" name="member_uid" class="form-control" placeholder="Enter or Scan Member ID" required autocomplete="off" oninput="fetchSuggestions('member', this)" onblur="fetchClearanceStatus(this.value)">
                    
                    <button type="button" class="btn-scan" onclick="startScanner('member_uid_input')" title="Scan Member ID">
                        <i class="fas fa-qrcode"></i>
                    </button>
                </div>
                <div id="member_uid_input_suggestions" class="autocomplete-list" style="display: none;"></div>
            </div>
        </div>
    </form>

    <div id="memberStatusCard">
        <h3 style="margin-top: 0; color: #4f46e5;"><i class="fas fa-user-check"></i> Member Status Verification</h3>
        
        <div class="status-grid">
            <div class="status-item">
                <label>Full Name</label>
                <span id="statusFullName">N/A</span>
            </div>
            <div class="status-item">
                <label>Member ID</label>
                <span id="statusMemberUID">N/A</span>
            </div>
            <div class="status-item">
                <label>Department</label>
                <span id="statusDept">N/A</span>
            </div>
            <div class="status-item">
                <label>Active Loans</label>
                <span id="statusIssuedCount" class="list-info">0</span>
            </div>
            <div class="status-item">
                <label>Outstanding Fines</label>
                <span id="statusFineTotal" class="list-danger">0</span>
            </div>
            <div class="status-item">
                <label>Active Reservations</label>
                <span id="statusResCount" class="list-warning">0</span>
            </div>
        </div>
        
        <div id="statusDetailsList" class="detail-list"></div>

        <div id="clearanceStatusBar" class="clear-status-bar status-unclear">
            <i class="fas fa-times-circle"></i> Verification Failed.
        </div>

        <div id="actionButtons" class="no-print">
             <button id="generateClearanceBtn" class="btn-generate" disabled onclick="generateClearance()">
                <i class="fas fa-certificate"></i> Generate Clearance Certificate
            </button>
        </div>
    </div>
</div>

<div id="scannerModal">
    <div id="scanner-container">
        <h3 style="text-align: center; margin-top: 0;">Scan Member ID</h3>
        <div id="reader"></div>
        <button onclick="stopScanner()" class="btn-main" style="background: #ef4444; margin-top: 15px; width: auto; display: inline-block; padding: 10px 20px;">Close Scanner</button>
    </div>
</div>

<script>
    // --- Member Auto-complete Logic ---
    function fetchSuggestions(uidType, inputElement) {
        const query = inputElement.value;
        const suggestionBox = document.getElementById(inputElement.id + '_suggestions');
        
        if (query.length < 3) {
            suggestionBox.style.display = 'none';
            inputElement.style.borderRadius = '12px';
            return;
        }

        if (inputElement.timeout) clearTimeout(inputElement.timeout);

        inputElement.timeout = setTimeout(() => {
            fetch('fetch_uids.php?uid_type=' + uidType + '&query=' + query)
                .then(response => response.json())
                .then(data => {
                    suggestionBox.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(item => {
                            const itemElement = document.createElement('div');
                            itemElement.className = 'autocomplete-item';
                            itemElement.textContent = item;
                            itemElement.onclick = function() {
                                inputElement.value = item;
                                suggestionBox.style.display = 'none';
                                inputElement.style.borderRadius = '12px'; // Reset
                                fetchClearanceStatus(item); // Trigger status check on selection
                            };
                            suggestionBox.appendChild(itemElement);
                        });
                        suggestionBox.style.display = 'block';
                        inputElement.style.borderRadius = '12px 12px 0 0';
                    } else {
                        suggestionBox.style.display = 'none';
                        inputElement.style.borderRadius = '12px';
                    }
                });
        }, 300);
    }

    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        const inputContainer = document.querySelector('.input-and-suggestions');
        if (inputContainer && !inputContainer.contains(e.target)) {
            const input = document.getElementById('member_uid_input');
            const box = document.getElementById('member_uid_input_suggestions');
            if (box && box.style.display !== 'none') {
                box.style.display = 'none';
                input.style.borderRadius = '12px';
            }
        }
    });

    // Expose globally
    window.fetchSuggestions = fetchSuggestions;


    // --- Clearance Status Fetching Logic ---
    let clearanceData = null;
    const currencySymbol = '<?php echo $currency; ?>';

    function fetchClearanceStatus(inputValue) {
        const card = document.getElementById('memberStatusCard');
        const statusBar = document.getElementById('clearanceStatusBar');
        const btn = document.getElementById('generateClearanceBtn');
        const detailList = document.getElementById('statusDetailsList');
        
        card.style.display = 'none';
        btn.disabled = true;
        detailList.innerHTML = '';

        if (inputValue.length === 0) return;

        // --- FIX: Extract ID from "Name (ID)" format ---
        let memberUid = inputValue;
        const match = inputValue.match(/\(([^)]+)\)$/);
        if (match) {
            memberUid = match[1];
        }
        // ------------------------------------------------
        
        // Show loading state
        card.style.display = 'block';
        document.getElementById('statusFullName').textContent = 'Checking...';
        statusBar.className = 'clear-status-bar status-unclear';
        statusBar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking Clearance Status Across System...';

        fetch(`library_clearance.php?action=fetch_status&member_uid=${memberUid}`)
            .then(response => response.json())
            .then(data => {
                clearanceData = data;
                
                if (data.success) {
                    // Populate main details
                    document.getElementById('statusFullName').textContent = data.member_details.full_name;
                    document.getElementById('statusMemberUID').textContent = data.member_details.member_uid;
                    document.getElementById('statusDept').textContent = data.member_details.department;
                    document.getElementById('statusIssuedCount').textContent = data.issued_count;
                    document.getElementById('statusFineTotal').textContent = currencySymbol + parseFloat(data.total_fines).toFixed(2);
                    document.getElementById('statusResCount').textContent = data.reservation_count;

                    // Populate status bar and button
                    if (data.cleared) {
                        statusBar.className = 'clear-status-bar status-cleared';
                        statusBar.innerHTML = '<i class="fas fa-check-circle"></i> CLEARANCE GRANTED';
                        btn.disabled = false;
                        btn.textContent = 'Generate Clearance Certificate';
                    } else {
                        statusBar.className = 'clear-status-bar status-unclear';
                        statusBar.innerHTML = '<i class="fas fa-times-circle"></i> CLEARANCE DENIED';
                        btn.disabled = true;
                        btn.textContent = 'Clearance Not Available (See Dues)';
                        
                        // Populate details list
                        let html = '';
                        if (data.issued_count > 0) {
                            html += '<h4><i class="fas fa-book-open"></i> Issued Books (' + data.issued_count + ')</h4><ul>';
                            data.issued_list.forEach(item => {
                                html += '<li class="list-danger">Title: <strong>' + item.title + '</strong> (UID: ' + item.book_uid + ') | Due: ' + item.due_date + ' | Status: ' + item.status + '</li>';
                            });
                            html += '</ul>';
                        }
                        
                        if (data.total_fines > 0) {
                            html += '<h4><i class="fas fa-coins"></i> Outstanding Fines (' + currencySymbol + parseFloat(data.total_fines).toFixed(2) + ')</h4><ul>';
                            data.fine_list.forEach(item => {
                                html += '<li class="list-danger">Type: <strong>' + item.fine_type + '</strong> | Amount: ' + currencySymbol + parseFloat(item.fine_amount).toFixed(2) + '</li>';
                            });
                            html += '</ul>';
                        }

                        if (data.reservation_count > 0) {
                            html += '<h4><i class="fas fa-calendar-check"></i> Active Reservations (' + data.reservation_count + ')</h4><ul>';
                            data.res_list.forEach(item => {
                                html += '<li class="list-warning">Title: <strong>' + item.title + '</strong> | Status: ' + item.status + '</li>';
                            });
                            html += '</ul>';
                        }

                        detailList.innerHTML = html;
                    }
                } else {
                    // Member not found/inactive
                    document.getElementById('statusFullName').textContent = data.message;
                    document.getElementById('statusMemberUID').textContent = 'N/A';
                    document.getElementById('statusDept').textContent = 'N/A';
                    statusBar.className = 'clear-status-bar status-unclear';
                    statusBar.innerHTML = '<i class="fas fa-times-circle"></i> Member Not Found or Inactive.';
                    btn.disabled = true;
                    btn.textContent = 'Enter a valid Member ID';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                document.getElementById('statusFullName').textContent = 'Error fetching data.';
                statusBar.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Network Error.';
            });
    }

    // --- Clearance Document Generation Logic ---
    function generateClearance() {
        if (!clearanceData || !clearanceData.cleared) {
            alert("Clearance cannot be generated as the member is not cleared.");
            return;
        }

        const member = clearanceData.member_details;
        const now = new Date();
        const generatedBy = '<?php echo htmlspecialchars($admin_name); ?>';
        
        // 1. Construct URL Parameters
        const params = new URLSearchParams({
            full_name: member.full_name,
            member_uid: member.member_uid,
            department: member.department,
            email: member.email,
            generated_by: generatedBy,
            timestamp: now.toISOString()
        });
        
        // 2. Open new print window
        window.open('print_clearance.php?' + params.toString(), '_blank');
    }

    // Expose functions globally
    window.fetchClearanceStatus = fetchClearanceStatus;
    window.generateClearance = generateClearance;

    // Reset view if input is cleared
    document.getElementById('member_uid_input').addEventListener('input', function() {
        if (this.value.length === 0) {
            document.getElementById('memberStatusCard').style.display = 'none';
        }
    });

    // --- SCANNER LOGIC (Added) ---
    let html5QrcodeScanner = null;
    let currentInputId = null;

    function startScanner(inputId) {
        currentInputId = inputId;
        document.getElementById('scannerModal').style.display = 'flex';

        if (html5QrcodeScanner === null) {
            html5QrcodeScanner = new Html5QrcodeScanner(
                "reader", 
                { fps: 10, qrbox: {width: 250, height: 250} },
                false
            );
            html5QrcodeScanner.render(onScanSuccess, onScanFailure);
        }
    }

    function onScanSuccess(decodedText, decodedResult) {
        if (currentInputId) {
            const inputField = document.getElementById(currentInputId);
            inputField.value = decodedText;
            
            // Automatically trigger fetch status
            fetchClearanceStatus(decodedText);
            
            stopScanner();
        }
    }

    function onScanFailure(error) {
        // handle scan failure, usually better to ignore to avoid console spam
    }

    function stopScanner() {
        document.getElementById('scannerModal').style.display = 'none';
        if(html5QrcodeScanner) {
            html5QrcodeScanner.clear().then(() => {
                html5QrcodeScanner = null; 
            }).catch(error => {
                console.error("Failed to clear scanner", error);
            });
        }
    }

</script>

<?php
admin_footer();
close_db_connection($conn);
?>