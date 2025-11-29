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

    /* Radio Cards for Options */
    .radio-group { display: flex; gap: 15px; margin-top: 10px; flex-wrap: wrap; }
    .radio-card {
        flex: 1; border: 1px solid #cbd5e1; border-radius: 10px; padding: 15px;
        cursor: pointer; transition: 0.2s; display: flex; align-items: center; gap: 10px;
        background: #fff; min-width: 140px;
    }
    .radio-card:hover { border-color: #4f46e5; background: #f5f3ff; }
    .radio-card input { accent-color: #4f46e5; transform: scale(1.2); }

    /* File Upload */
    .file-upload-wrapper {
        border: 2px dashed #cbd5e1;
        padding: 20px;
        text-align: center;
        border-radius: 10px;
        margin-top: 15px;
        background: #f8fafc;
        cursor: pointer;
        transition: 0.2s;
        position: relative;
    }
    .file-upload-wrapper:hover { border-color: #4f46e5; background: #eef2ff; }
    .hidden { display: none !important; }

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
        <p style="color: #6b7280; margin-top: 5px;">Search member and configure print settings.</p>
    </div>

    <form action="print_member_labels_output.php" method="POST" target="_blank" id="labelForm" enctype="multipart/form-data">
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
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label class="form-label">Label Code Type</label>
                    <div class="radio-group">
                        <label class="radio-card">
                            <input type="radio" name="label_type" value="qrcode" checked>
                            <div><i class="fas fa-qrcode" style="color: #4f46e5;"></i> QR Code</div>
                        </label>
                        <label class="radio-card">
                            <input type="radio" name="label_type" value="barcode">
                            <div><i class="fas fa-barcode" style="color: #4f46e5;"></i> Barcode</div>
                        </label>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Print Medium</label>
                    <div class="radio-group">
                        <label class="radio-card">
                            <input type="radio" name="print_medium" value="card" checked onchange="updateSettings()">
                            <div><i class="fas fa-id-card" style="color: #10b981;"></i> Card (ID-1)</div>
                        </label>
                        <label class="radio-card">
                            <input type="radio" name="print_medium" value="paper" onchange="updateSettings()">
                            <div><i class="fas fa-file-alt" style="color: #f59e0b;"></i> Paper (A4)</div>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Number of Copies</label>
                <input type="number" id="copy_count" name="copy_count" class="form-control" value="1" min="1" max="50">
            </div>

            <div class="form-group">
                <label class="form-label">Print Layout</label>
                <div class="radio-group">
                    <label class="radio-card">
                        <input type="radio" name="print_layout" value="landscape" checked>
                        <div><i class="fas fa-image" style="transform: rotate(90deg); color: #4f46e5;"></i> Landscape</div>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="print_layout" value="portrait">
                        <div><i class="fas fa-image" style="color: #4f46e5;"></i> Portrait</div>
                    </label>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Member Photo</label>
                <div class="radio-group">
                    <label class="radio-card">
                        <input type="radio" name="photo_option" value="without" checked onchange="togglePhotoUpload()">
                        <div><i class="fas fa-user-slash" style="color: #64748b;"></i> Without Photo</div>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="photo_option" value="with" onchange="togglePhotoUpload()">
                        <div><i class="fas fa-user-circle" style="color: #4f46e5;"></i> With Photo</div>
                    </label>
                </div>

                <div id="photo_upload_container" class="hidden">
                    <div class="file-upload-wrapper" onclick="document.getElementById('member_photo').click()">
                        <input type="file" name="member_photo" id="member_photo" accept="image/*" style="display:none" onchange="previewImage(this, 'photo_preview_img', 'photo_preview_text')">
                        <div id="photo_preview_text">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 1.5rem; color: #4f46e5;"></i><br>
                            <span style="font-size: 0.9rem; color: #64748b;">Click to upload photo (Temp)</span>
                        </div>
                        <img id="photo_preview_img" src="" style="max-height: 100px; display: none; margin: 0 auto; border-radius: 8px; border: 1px solid #cbd5e1;">
                    </div>
                </div>
            </div>
            

            <!--
            <div class="form-group">
                <label class="form-label">Authority Signature</label>
                <div class="radio-group">
                     <label class="radio-card">
                        <input type="radio" name="signature_option" value="without" checked onchange="toggleSignatureUpload()">
                        <div><i class="fas fa-pen-slash" style="color: #64748b;"></i> None</div>
                    </label>
                    <label class="radio-card">
                        <input type="radio" name="signature_option" value="with" onchange="toggleSignatureUpload()">
                        <div><i class="fas fa-file-signature" style="color: #4f46e5;"></i> Upload Signature</div>
                    </label>
                </div>

                <div id="signature_upload_container" class="hidden">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div class="file-upload-wrapper" onclick="document.getElementById('signature_image').click()">
                            <input type="file" name="signature_image" id="signature_image" accept="image/*" style="display:none" onchange="previewImage(this, 'sig_preview_img', 'sig_preview_text')">
                            <div id="sig_preview_text">
                                <i class="fas fa-pen-fancy" style="font-size: 1.5rem; color: #4f46e5;"></i><br>
                                <span style="font-size: 0.9rem; color: #64748b;">Upload Signature</span>
                            </div>
                            <img id="sig_preview_img" src="" style="max-height: 60px; display: none; margin: 0 auto; object-fit: contain;">
                        </div>
                        <div>
                            <label class="form-label" style="font-size: 0.85rem;">Label Text</label>
                            <input type="text" name="signature_text" class="form-control" value="Authorized Signatory" placeholder="e.g., Librarian">
                        </div>
                    </div>
                </div>
            </div>  
            -->

            

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
            fetch('fetch_uids.php?uid_type=member&query=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(data => {
                    box.innerHTML = '';
                    if (data.length > 0) {
                        data.forEach(text => {
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
        const match = text.match(/\(([^)]+)\)$/);
        if (!match) return;
        
        const uid = match[1];
        document.getElementById('member_search').value = text;
        document.getElementById('suggestions_box').style.display = 'none';

        fetch('fetch_member_details.php?member_uid=' + encodeURIComponent(uid))
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('selected_uid').value = data.member_uid;
                    document.getElementById('selected_name').value = data.full_name;
                    document.getElementById('selected_dept').value = data.department;

                    document.getElementById('display_name').textContent = data.full_name;
                    document.getElementById('display_uid').textContent = data.member_uid;
                    document.getElementById('display_dept').textContent = data.department;
                    document.getElementById('display_email').textContent = data.email;
                    
                    const statusEl = document.getElementById('display_status');
                    statusEl.textContent = data.status;
                    statusEl.style.color = data.status === 'Active' ? '#10b981' : '#ef4444';

                    document.getElementById('memberDetails').style.display = 'block';
                    document.getElementById('labelOptions').style.display = 'block';
                } else {
                    alert('Member details could not be fetched.');
                }
            });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.input-wrapper')) {
            document.getElementById('suggestions_box').style.display = 'none';
        }
    });

    function updateSettings() {
        const medium = document.querySelector('input[name="print_medium"]:checked').value;
        const copyInput = document.getElementById('copy_count');
        
        if (medium === 'card') {
            copyInput.value = 1;
        } else {
            copyInput.value = 2; // Default for paper
        }
    }

    function togglePhotoUpload() {
        const option = document.querySelector('input[name="photo_option"]:checked').value;
        const container = document.getElementById('photo_upload_container');
        const input = document.getElementById('member_photo');
        
        if (option === 'with') {
            container.classList.remove('hidden');
            input.required = true;
        } else {
            container.classList.add('hidden');
            input.required = false;
            input.value = ''; 
            document.getElementById('photo_preview_img').style.display = 'none';
            document.getElementById('photo_preview_text').style.display = 'block';
        }
    }
    
    function toggleSignatureUpload() {
        const option = document.querySelector('input[name="signature_option"]:checked').value;
        const container = document.getElementById('signature_upload_container');
        const input = document.getElementById('signature_image');
        
        if (option === 'with') {
            container.classList.remove('hidden');
            input.required = true;
        } else {
            container.classList.add('hidden');
            input.required = false;
            input.value = '';
            document.getElementById('sig_preview_img').style.display = 'none';
            document.getElementById('sig_preview_text').style.display = 'block';
        }
    }

    function previewImage(input, imgId, textId) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById(textId).style.display = 'none';
                const img = document.getElementById(imgId);
                img.src = e.target.result;
                img.style.display = 'block';
            }
            reader.readAsDataURL(input.files[0]);
        }
    }
</script>

<?php
admin_footer();
close_db_connection($conn);
?>
