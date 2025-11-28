<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

admin_header('Print Member Labels');
?>

<style>
    /* Glass Card Styles matching the theme */
    .glass-card {
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(15px);
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        margin-bottom: 30px;
        max-width: 800px;
        margin: 0 auto;
    }
    .card-header h2 { margin: 0; color: #111827; display: flex; align-items: center; gap: 10px; }
    
    /* Form Controls */
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 8px; color: #374151; }
    .form-control {
        width: 100%; padding: 12px; border-radius: 8px; 
        border: 1px solid #cbd5e1; font-size: 1rem;
        transition: all 0.3s;
    }
    .form-control:focus { border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); outline: none; }

    /* Autocomplete */
    .input-wrapper { position: relative; }
    .autocomplete-list {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 1000;
        background: white; border: 1px solid #cbd5e1; border-top: none;
        border-radius: 0 0 8px 8px; max-height: 200px; overflow-y: auto;
        box-shadow: 0 10px 15px rgba(0,0,0,0.1); display: none;
    }
    .autocomplete-item { padding: 10px 15px; cursor: pointer; font-size: 0.95rem; color: #111827; }
    .autocomplete-item:hover { background: #eef2ff; color: #4f46e5; }

    /* Member Details Card */
    .member-card {
        display: none; /* Hidden initially */
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 25px;
        animation: slideDown 0.3s ease-out;
    }
    .mc-header { display: flex; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 10px; margin-bottom: 15px; }
    .mc-name { font-size: 1.2rem; font-weight: 700; color: #111827; }
    .mc-uid { font-family: monospace; color: #4f46e5; background: #e0e7ff; padding: 2px 8px; border-radius: 4px; }
    
    .mc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .mc-item label { font-size: 0.8rem; color: #64748b; display: block; text-transform: uppercase; letter-spacing: 0.5px; }
    .mc-item span { font-weight: 600; color: #334155; font-size: 1rem; }

    /* Radio Cards for Label Type */
    .radio-group { display: flex; gap: 15px; margin-top: 10px; }
    .radio-card {
        flex: 1; border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px;
        cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 10px;
        background: #fff;
    }
    .radio-card:hover { border-color: #4f46e5; background: #f5f3ff; }
    .radio-card input { accent-color: #4f46e5; transform: scale(1.2); }

    /* Button */
    .btn-print {
        background: linear-gradient(135deg, #4f46e5, #3730a3); color: white;
        border: none; padding: 15px 30px; border-radius: 10px;
        font-weight: 700; font-size: 1rem; cursor: pointer; width: 100%;
        display: flex; align-items: center; justify-content: center; gap: 10px;
        transition: transform 0.2s; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
    }
    .btn-print:hover { transform: translateY(-2px); }
    
    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
</style>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-id-badge" style="color: #4f46e5;"></i> Print Member ID Labels</h2>
        <p style="color: #6b7280; margin-top: 5px;">Search for a member to generate their library ID.</p>
    </div>

    <form action="print_member_labels_output.php" method="POST" target="_blank" id="labelForm">
        <div class="form-group">
            <label class="form-label">Search Member</label>
            <div class="input-wrapper">
                <input type="text" id="member_search" class="form-control" placeholder="Start typing Name or Member ID..." autocomplete="off" oninput="fetchSuggestions(this)">
                <div id="suggestions_box" class="autocomplete-list"></div>
            </div>
        </div>

        <div id="memberDetails" class="member-card">
            <input type="hidden" name="member_uid" id="selected_uid">
            <input type="hidden" name="member_name" id="selected_name">
            <input type="hidden" name="member_dept" id="selected_dept">
            
            <div class="mc-header">
                <div class="mc-name" id="display_name">Loading...</div>
                <div class="mc-uid" id="display_uid">...</div>
            </div>
            <div class="mc-grid">
                <div class="mc-item">
                    <label>Department</label>
                    <span id="display_dept">...</span>
                </div>
                <div class="mc-item">
                    <label>Email</label>
                    <span id="display_email">...</span>
                </div>
                <div class="mc-item">
                    <label>Account Status</label>
                    <span id="display_status" style="color: #10b981;">...</span>
                </div>
            </div>
        </div>

        <div id="labelOptions" style="display: none;">
            <div class="form-group">
                <label class="form-label">Select Label Code Type</label>
                <div class="radio-group">
                    <label class="radio-card">
                        <input type="radio" name="label_type" value="qrcode" checked>
                        <div><i class="fas fa-qrcode" style="font-size: 1.2rem; color: #4f46e5;"></i> <strong>QR Code</strong></div>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="label_type" value="barcode">
                        <div><i class="fas fa-barcode" style="font-size: 1.2rem; color: #4f46e5;"></i> <strong>Barcode</strong></div>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn-print">
                <i class="fas fa-print"></i> Generate Print View
            </button>
        </div>
    </form>
</div>

<script>
    let debounceTimer;

    function fetchSuggestions(input) {
        const query = input.value.trim();
        const box = document.getElementById('suggestions_box');
        
        if (query.length < 2) {
            box.style.display = 'none';
            return;
        }

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            // Utilizing existing API
            fetch('fetch_uids.php?uid_type=member&query=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    box.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(text => {
                            // Text format is "Name (UID)"
                            const div = document.createElement('div');
                            div.className = 'autocomplete-item';
                            div.textContent = text;
                            div.onclick = () => selectMember(text);
                            box.appendChild(div);
                        });
                        box.style.display = 'block';
                    } else {
                        box.style.display = 'none';
                    }
                })
                .catch(err => console.error('Error:', err));
        }, 300);
    }

    function selectMember(text) {
        // Extract UID from "Name (UID)" format
        const match = text.match(/\(([^)]+)\)$/);
        if (!match) return;
        
        const uid = match[1];
        document.getElementById('member_search').value = text;
        document.getElementById('suggestions_box').style.display = 'none';

        // Fetch full details
        fetch('fetch_member_details.php?member_uid=' + encodeURIComponent(uid))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Populate Hidden Inputs
                    document.getElementById('selected_uid').value = data.member_uid;
                    document.getElementById('selected_name').value = data.full_name;
                    document.getElementById('selected_dept').value = data.department;

                    // Populate Display
                    document.getElementById('display_name').textContent = data.full_name;
                    document.getElementById('display_uid').textContent = data.member_uid;
                    document.getElementById('display_dept').textContent = data.department;
                    document.getElementById('display_email').textContent = data.email;
                    
                    const statusEl = document.getElementById('display_status');
                    statusEl.textContent = data.status;
                    statusEl.style.color = data.status === 'Active' ? '#10b981' : '#ef4444';

                    // Show sections
                    document.getElementById('memberDetails').style.display = 'block';
                    document.getElementById('labelOptions').style.display = 'block';
                } else {
                    alert('Member details could not be fetched.');
                }
            });
    }

    // Close suggestions on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.input-wrapper')) {
            document.getElementById('suggestions_box').style.display = 'none';
        }
    });
</script>

<?php
admin_footer();
close_db_connection($conn);
?>