<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

if (!is_super_admin($conn)) {
    redirect('index.php');
}

$message = '';
$error = '';
$tab = $_GET['tab'] ?? 'general'; // Default tab

// --- PHP LOGIC (Unchanged resize_logo function) ---
function resize_logo($source_path, $dest_path, $target_height) {
    list($width, $height, $type) = getimagesize($source_path);
    $ratio = $width / $height;
    $new_width = $target_height * $ratio;
    $new_height = $target_height;

    $src = null;
    if ($type == IMAGETYPE_JPEG) $src = imagecreatefromjpeg($source_path);
    elseif ($type == IMAGETYPE_PNG) $src = imagecreatefrompng($source_path);

    if ($src) {
        $dst = imagecreatetruecolor($new_width, $new_height);
        if ($type == IMAGETYPE_PNG) {
            imagecolortransparent($dst, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        if ($type == IMAGETYPE_PNG) imagepng($dst, $dest_path);
        else imagejpeg($dst, $dest_path, 90);
        
        imagedestroy($src);
        imagedestroy($dst);
        return true;
    }
    return false;
}

// --- LIBRARY CRUD LOGIC ---

$libraries = [];
$libraries_result = $conn->query("SELECT * FROM tbl_libraries ORDER BY library_name ASC");
while ($row = $libraries_result->fetch_assoc()) {
    $libraries[] = $row;
}

$library_edit_data = null;
if ($tab === 'libraries' && isset($_GET['action'])) {
    if ($_GET['action'] === 'edit' && isset($_GET['id'])) {
        $library_id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT * FROM tbl_libraries WHERE library_id = ?");
        $stmt->bind_param("i", $library_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $library_edit_data = $result->fetch_assoc();
        if (!$library_edit_data) {
            $error = "Library not found.";
            $tab = 'libraries';
        }
    } elseif ($_GET['action'] === 'delete' && isset($_GET['id'])) {
        $library_id = (int)$_GET['id'];
        
        // Prevent deletion if this library is assigned to any admin
        $stmt_check_admin = $conn->prepare("SELECT COUNT(*) FROM tbl_admin WHERE library_id = ?");
        $stmt_check_admin->bind_param("i", $library_id);
        $stmt_check_admin->execute();
        $admin_count = $stmt_check_admin->get_result()->fetch_row()[0];
        
        if ($admin_count > 0) {
            $error = "Cannot delete library. $admin_count admin(s)/librarian(s) are currently assigned to it. Please reassign them first from the Members section.";
        } else {
            // Delete the library (This should cascade delete book copies as per your SQL)
            $stmt = $conn->prepare("DELETE FROM tbl_libraries WHERE library_id = ?");
            $stmt->bind_param("i", $library_id);
            if ($stmt->execute()) {
                $message = "Library deleted successfully.";
            } else {
                $error = "Error deleting library: " . $conn->error;
            }
        }
        
        // Redirect to clear GET parameters after action
        redirect('settings.php?tab=libraries' . ($message ? '&msg=' . urlencode($message) : ''));
    }
}

// Handle Library POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'libraries') {
    $library_name = trim($_POST['library_name'] ?? '');
    $library_initials = strtoupper(trim($_POST['library_initials'] ?? ''));
    $library_location = trim($_POST['library_location'] ?? '');
    $library_id = (int)($_POST['library_id'] ?? 0);
    $tab = 'libraries'; // Ensure the tab stays on libraries on error/success

    if (empty($library_name) || empty($library_initials)) {
        $error = "Library Name and Initials are required.";
    } elseif (!preg_match('/^[a-zA-Z0-9]+$/', $library_initials)) {
        $error = "Library Initials must be alphanumeric.";
    } else {
        // Check for unique initials
        $check_sql = "SELECT library_id FROM tbl_libraries WHERE library_initials = ?";
        if ($library_id > 0) {
            $check_sql .= " AND library_id != ?";
        }
        $stmt_check = $conn->prepare($check_sql);
        
        if ($library_id > 0) {
             $stmt_check->bind_param("si", $library_initials, $library_id);
        } else {
             $stmt_check->bind_param("s", $library_initials);
        }
        $stmt_check->execute();
        if ($stmt_check->get_result()->num_rows > 0) {
            $error = "Library Initials already exist. Please choose another one.";
        } else {
            if ($library_id > 0) {
                // Update
                $stmt = $conn->prepare("UPDATE tbl_libraries SET library_name = ?, library_initials = ?, library_location = ? WHERE library_id = ?");
                $stmt->bind_param("sssi", $library_name, $library_initials, $library_location, $library_id);
                if ($stmt->execute()) {
                    $message = "Library updated successfully!";
                } else {
                    $error = "Error updating library: " . $conn->error;
                }
            } else {
                // Insert
                $stmt = $conn->prepare("INSERT INTO tbl_libraries (library_name, library_initials, library_location) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $library_name, $library_initials, $library_location);
                if ($stmt->execute()) {
                    $message = "New library created successfully!";
                } else {
                    $error = "Error creating library: " . $conn->error;
                }
            }
        }
    }
    
    // Refresh libraries list and reset edit form data if successful
    if (!$error) {
        // Redirect to clear POST data and show message
        redirect('settings.php?tab=libraries&msg=' . urlencode($message));
    } else {
        // Keep the submitted data in case of error for repopulating the form
        $library_edit_data = [
            'library_id' => $library_id,
            'library_name' => $library_name,
            'library_initials' => $library_initials,
            'library_location' => $library_location,
        ];
    }
}


// --- GENERAL SETTINGS POST LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tab === 'general') {
    // [FIXED] Added the new fields to the array
    $settings_to_update = [
        'institution_name' => $_POST['institution_name'],
        'institution_initials' => strtoupper($_POST['institution_initials']),
        'fine_per_day' => $_POST['fine_per_day'],
        'currency_symbol' => $_POST['currency_symbol'],
        'max_borrow_days' => $_POST['max_borrow_days'],
        'max_borrow_limit' => $_POST['max_borrow_limit'],
        'online_password_reset' => $_POST['online_password_reset'] ?? '0', 
        'reminder_days' => $_POST['reminder_days'] ?? '',
    ];

    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['logo_file']['tmp_name'];
        $fileName = $_FILES['logo_file']['name'];
        $fileCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileCmps));
        
        if (in_array($fileExtension, ['jpg', 'jpeg', 'png'])) {
            $uploadDir = '../uploads/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $newFileName = 'logo_' . time() . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;
            
            if (resize_logo($fileTmpPath, $destPath, 100)) {
                $settings_to_update['institution_logo'] = 'uploads/logos/' . $newFileName;
            } else {
                move_uploaded_file($fileTmpPath, $destPath);
                $settings_to_update['institution_logo'] = 'uploads/logos/' . $newFileName;
            }
        } else {
            $error = "Invalid logo format. Only JPG/PNG allowed.";
        }
    }

    if (!$error) {
        foreach ($settings_to_update as $key => $value) {
            $stmt_check = $conn->prepare("SELECT setting_key FROM tbl_settings WHERE setting_key = ?");
            $stmt_check->bind_param("s", $key);
            $stmt_check->execute();
            if($stmt_check->get_result()->num_rows == 0) {
                 $stmt_ins = $conn->prepare("INSERT INTO tbl_settings (setting_key, setting_value) VALUES (?, ?)");
                 $stmt_ins->bind_param("ss", $key, $value);
                 $stmt_ins->execute();
            } else {
                 $stmt = $conn->prepare("UPDATE tbl_settings SET setting_value = ? WHERE setting_key = ?");
                 $stmt->bind_param("ss", $value, $key);
                 $stmt->execute();
            }
        }
        $message = "System configuration updated successfully!";
    }
    
    // Redirect to clear POST data and stay on the general tab
    if (!$error) {
        redirect('settings.php?tab=general&msg=' . urlencode($message));
    }
}

// Handle URL message parameter from successful redirects
if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

$settings = [];
$result = $conn->query("SELECT setting_key, setting_value FROM tbl_settings");
while ($row = $result->fetch_assoc()) $settings[$row['setting_key']] = $row['setting_value'];

admin_header('System Configuration');
?>

<style>
    :root {
        --primary-color: #4f46e5; /* Modern Indigo */
        --primary-hover: #4338ca;
        --secondary-color: #ec4899; /* Pink accent */
        --bg-color: #f3f4f6;
        --card-bg: #ffffff;
        --text-dark: #1f2937;
        --text-light: #6b7280;
        --border-color: #e5e7eb;
        --success-bg: #d1fae5;
        --success-text: #065f46;
        --danger-bg: #fee2e2;
        --danger-text: #991b1b;
    }

    body {
        background-color: var(--bg-color);
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }

    .settings-wrapper {
        max-width: 1100px;
        margin: 0 auto;
        padding: 30px 20px;
        animation: fadeIn 0.5s ease-in-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .page-header {
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .page-header h2 {
        font-size: 2rem;
        color: var(--text-dark);
        font-weight: 700;
        margin: 0;
    }

    .grid-container {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }

    @media (max-width: 900px) {
        .grid-container { grid-template-columns: 1fr; }
    }

    .settings-card {
        background: var(--card-bg);
        border-radius: 16px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025);
        padding: 30px;
        border: 1px solid var(--border-color);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        position: relative;
        overflow: hidden;
    }

    .settings-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    }

    /* Decorative top bar for cards */
    .settings-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 6px;
        background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
    }

    .card-header {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid var(--bg-color);
    }

    .card-header i {
        font-size: 1.5rem;
        background: #e0e7ff;
        color: var(--primary-color);
        padding: 12px;
        border-radius: 12px;
    }

    .card-header h3 {
        margin: 0;
        font-size: 1.25rem;
        color: var(--text-dark);
    }

    .form-group {
        margin-bottom: 25px;
        position: relative;
    }

    .form-group label {
        display: block;
        font-size: 0.9rem;
        font-weight: 600;
        color: var(--text-dark);
        margin-bottom: 8px;
    }

    .form-control {
        width: 85%;
        padding: 12px 16px;
        font-size: 1rem;
        border: 2px solid var(--border-color);
        border-radius: 10px;
        background-color: #f9fafb;
        transition: all 0.2s;
    }

    .form-control:focus {
        background-color: #fff;
        border-color: var(--primary-color);
        box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.1);
        outline: none;
    }

    /* Drag and Drop Zone Styles */
    .drop-zone {
        border: 2px dashed var(--border-color);
        border-radius: 12px;
        padding: 20px;
        text-align: center;
        background: #f9fafb;
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
    }

    .drop-zone:hover, .drop-zone.dragover {
        border-color: var(--primary-color);
        background: #eef2ff;
    }

    .drop-zone-content {
        pointer-events: none; /* Let clicks pass through to container */
    }
    
    .drop-zone input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
    }

    .preview-image {
        max-height: 100px;
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        margin-top: 10px;
        display: none; /* Hidden by default until JS runs */
        margin-left: auto; 
        margin-right: auto;
    }

    .current-image-wrapper {
        text-align: center;
        margin-bottom: 15px;
    }

    /* Save Button */
    .save-btn-container {
        grid-column: 1 / -1;
        margin-top: 20px;
        display: flex;
        justify-content: flex-end;
    }

    .btn-save {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
        color: white;
        border: none;
        padding: 16px 40px;
        font-size: 1.1rem;
        font-weight: 600;
        border-radius: 50px;
        cursor: pointer;
        box-shadow: 0 10px 20px rgba(79, 70, 229, 0.3);
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .btn-save:hover {
        transform: translateY(-3px);
        box-shadow: 0 15px 30px rgba(79, 70, 229, 0.4);
    }
    
    .btn-save:active {
        transform: translateY(-1px);
    }

    .alert {
        padding: 16px;
        border-radius: 12px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideDown 0.4s ease-out;
    }
    
    @keyframes slideDown {
        from { opacity: 0; transform: translateY(-20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .alert-success { background-color: var(--success-bg); color: var(--success-text); }
    .alert-danger { background-color: var(--danger-bg); color: var(--danger-text); }

    /* Live Calculator Box */
    .live-calc {
        background: #fffbeb;
        border: 1px solid #fcd34d;
        color: #92400e;
        padding: 10px;
        border-radius: 8px;
        font-size: 0.85rem;
        margin-top: 5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* Tab Navigation Styles */
    .tab-nav {
        display: flex;
        margin-bottom: 20px;
        border-bottom: 2px solid var(--border-color);
    }

    .tab-nav-link {
        padding: 10px 20px;
        text-decoration: none;
        color: var(--text-light);
        font-weight: 600;
        transition: all 0.3s;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px; /* Pulls the link border over the main border */
    }

    .tab-nav-link:hover {
        color: var(--primary-color);
    }

    .tab-nav-link.active {
        color: var(--primary-color);
        border-bottom: 3px solid var(--primary-color);
    }

    /* Styles for the Library Management table */
    .library-table-wrapper {
        overflow-x: auto;
    }

    .library-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 10px; /* Space between rows */
    }
    
    /* Apply borders to individual cells for modern look */
    .library-table td {
        background-color: var(--card-bg);
        border: 1px solid var(--border-color);
        border-width: 1px 0; /* Horizontal borders */
    }

    .library-table th, .library-table td {
        padding: 12px 15px;
        text-align: left;
    }

    .library-table th {
        background-color: var(--bg-color);
        color: var(--text-dark);
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.8rem;
    }
    
    .library-table tbody tr:first-child td { border-top-width: 1px; }

    .library-table tr:hover td {
        background-color: #fcfcfc;
    }

    .library-table tr td:first-child { border-left: 1px solid var(--border-color); border-top-left-radius: 8px; border-bottom-left-radius: 8px;}
    .library-table tr td:last-child { border-right: 1px solid var(--border-color); border-top-right-radius: 8px; border-bottom-right-radius: 8px;}

    .action-btn {
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        text-decoration: none;
        font-size: 0.9rem;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        margin-right: 5px;
        transition: background 0.2s;
    }

    .action-btn.edit {
        background-color: #3b82f6; /* Blue */
    }

    .action-btn.delete {
        background-color: #ef4444; /* Red */
    }
    
    .action-btn:hover {
        filter: brightness(1.1);
    }

    /* LOADING OVERLAY STYLES */
    #loadingOverlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.85); /* Semi-transparent white */
        backdrop-filter: blur(5px);
        z-index: 9999;
        display: none; /* Hidden by default */
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }

    .loading-content {
        background: white;
        padding: 40px;
        border-radius: 20px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.1);
        text-align: center;
        border: 1px solid #e5e7eb;
    }

    .spinner {
        width: 50px;
        height: 50px;
        border: 5px solid #e0e7ff;
        border-top: 5px solid var(--primary-color);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px auto;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .loading-text {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--text-dark);
        margin: 0;
    }

    .loading-subtext {
        color: var(--text-light);
        font-size: 0.9rem;
        margin-top: 5px;
    }
</style>

<div id="loadingOverlay">
    <div class="loading-content">
        <div class="spinner"></div>
        <h3 class="loading-text">Updating Settings...</h3>
        <p class="loading-subtext">Please wait while we save your changes.</p>
    </div>
</div>

<div class="settings-wrapper">

    <?php if ($message): ?> 
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> 
    <?php endif; ?>
    
    <?php if ($error): ?> 
        <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> 
    <?php endif; ?>
    
    <div class="tab-nav">
        <a href="settings.php?tab=general" class="tab-nav-link <?php echo $tab === 'general' ? 'active' : ''; ?>">General Settings</a>
        <a href="settings.php?tab=libraries" class="tab-nav-link <?php echo $tab === 'libraries' ? 'active' : ''; ?>">Library Management</a>
    </div>

    <div class="page-header">
        <h2><?php echo $tab === 'general' ? 'General System Configuration' : 'Library Management'; ?></h2>
    </div>

    <?php if ($tab === 'general'): ?>
    
        <form method="POST" enctype="multipart/form-data" id="settingsForm">
            <div class="grid-container">
                
                <div class="settings-card">
                    <div class="card-header">
                        <i class="fas fa-university"></i>
                        <h3>Institution Identity & Branding</h3>
                    </div>

                    <div class="form-group">
                        <label>Institution Name</label>
                        <input type="text" class="form-control" id="instName" name="institution_name" value="<?php echo htmlspecialchars($settings['institution_name'] ?? ''); ?>" required placeholder="e.g. Brainware University">
                    </div>

                    <div class="form-group">
                        <label>Institution Initials</label>
                        <input type="text" class="form-control" id="instInitials" name="institution_initials" value="<?php echo htmlspecialchars($settings['institution_initials'] ?? ''); ?>" required placeholder="BWU">
                        <small style="color: var(--primary-color); cursor: pointer; font-size: 0.8rem;" id="autoGenBtn">Auto-generate</small>
                    </div>

                    <div class="form-group">
                        <label>Institution Logo</label>
                        
                        <div class="drop-zone" id="dropZone">
                            <div class="current-image-wrapper">
                                <?php if (!empty($settings['institution_logo'])): ?>
                                    <img src="../<?php echo $settings['institution_logo']; ?>" id="currentLogo" style="height: 60px; margin-bottom: 10px;" alt="Current Logo">
                                <?php else: ?>
                                    <i class="fas fa-image fa-3x" style="color: #cbd5e1; margin-bottom: 10px;"></i>
                                <?php endif; ?>
                                <img id="imagePreview" class="preview-image" src="#" alt="New Logo Preview">
                            </div>
                            
                            <div class="drop-zone-content">
                                <h4 style="margin:0; font-size: 1rem; color: var(--text-dark);">Click to upload or drag & drop</h4>
                                <p style="margin:5px 0 0; font-size: 0.8rem; color: var(--text-light);">PNG or JPG (Max 2MB)</p>
                            </div>
                            <input type="file" name="logo_file" id="logoFile" accept="image/png, image/jpeg">
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="card-header">
                        <i class="fas fa-gavel"></i>
                        <h3>Rules & Limits</h3>
                    </div>

                    <div class="form-group">
                        <label>Currency Symbol</label>
                        <input type="text" class="form-control" id="currSymbol" name="currency_symbol" value="<?php echo htmlspecialchars($settings['currency_symbol'] ?? ''); ?>" required placeholder="e.g. ₹">
                    </div>

                    <div class="form-group">
                        <label>Late Fine (Per Day)</label>
                        <input type="number" step="0.01" class="form-control" id="fineAmount" name="fine_per_day" value="<?php echo htmlspecialchars($settings['fine_per_day'] ?? ''); ?>" required>
                        <div class="live-calc">
                            <i class="fas fa-calculator"></i>
                            Estimated Weekly Fine: <strong id="calcPreview"></strong>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Default Borrow Duration (Days)</label>
                        <input type="number" class="form-control" name="max_borrow_days" value="<?php echo htmlspecialchars($settings['max_borrow_days'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Max Books Per User</label>
                        <input type="number" class="form-control" name="max_borrow_limit" value="<?php echo htmlspecialchars($settings['max_borrow_limit'] ?? ''); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Online Password Reset (OTP)</label>
                        <div class="input-wrapper">
                            <i class="fas fa-key" style="position:absolute; left:15px; top:50%; transform:translateY(-50%); color:#6b7280;"></i>
                            <select name="online_password_reset" class="form-control" style="padding-left:40px;">
                                <option value="0" <?php echo ($settings['online_password_reset'] ?? '0') == '0' ? 'selected' : ''; ?>>Disabled (Show Instructions)</option>
                                <option value="1" <?php echo ($settings['online_password_reset'] ?? '0') == '1' ? 'selected' : ''; ?>>Enabled (Send Email OTP)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Due Date Reminder Days</label>
                        <div class="input-wrapper">
                            <i class="fas fa-bell" style="position:absolute; left:15px; top:50%; transform:translateY(-50%); color:#6b7280;"></i>
                            <input type="text" class="form-control" style="padding-left:40px;" name="reminder_days" value="<?php echo htmlspecialchars($settings['reminder_days'] ?? '1,3'); ?>" placeholder="e.g. 1,3,7">
                        </div>
                        <small style="color: #6b7280; font-size: 0.8rem;">Send emails X days before due date. Separate multiple days with commas.</small>
                    </div>
                </div>

                <div class="save-btn-container">
                    <button type="submit" class="btn-save">
                        <span>Save Configuration</span>
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </div>

            </div>
        </form>
    
    <?php elseif ($tab === 'libraries'): ?>
    
        <div class="grid-container" style="grid-template-columns: 1fr;">

            <div class="settings-card">
                <div class="card-header">
                    <i class="fas fa-plus-circle"></i>
                    <h3><?php echo $library_edit_data ? 'Edit Library: ' . htmlspecialchars($library_edit_data['library_name']) : 'Add New Library'; ?></h3>
                </div>
                <form method="POST" id="libraryForm">
                    <?php if ($library_edit_data): ?>
                        <input type="hidden" name="library_id" value="<?php echo (int)$library_edit_data['library_id']; ?>">
                    <?php endif; ?>

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                        <div class="form-group">
                            <label>Library Name</label>
                            <input type="text" class="form-control" name="library_name" value="<?php echo htmlspecialchars($library_edit_data['library_name'] ?? ''); ?>" required placeholder="e.g. Science Block Library">
                        </div>
                        <div class="form-group">
                            <label>Library Initials</label>
                            <input type="text" class="form-control" name="library_initials" value="<?php echo htmlspecialchars($library_edit_data['library_initials'] ?? ''); ?>" required placeholder="SBLIB">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Library Location</label>
                        <input type="text" class="form-control" name="library_location" value="<?php echo htmlspecialchars($library_edit_data['library_location'] ?? ''); ?>" placeholder="e.g. 3rd Floor, Central Building">
                    </div>

                    <button type="submit" class="action-btn edit" style="margin-top: 10px;">
                        <i class="fas fa-save"></i>
                        <span><?php echo $library_edit_data ? 'Update Library' : 'Create Library'; ?></span>
                    </button>
                    <?php if ($library_edit_data): ?>
                        <a href="settings.php?tab=libraries" class="action-btn" style="background-color: #6b7280;"><i class="fas fa-times"></i> Cancel Edit</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="settings-card" style="margin-top: 30px;">
                <div class="card-header">
                    <i class="fas fa-list-ul"></i>
                    <h3>Existing Libraries (<?php echo count($libraries); ?>)</h3>
                </div>
                
                <?php if (!empty($libraries)): ?>
                    <div class="library-table-wrapper">
                        <table class="library-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Initials</th>
                                    <th>Location</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($libraries as $lib): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($lib['library_name']); ?></td>
                                        <td><?php echo htmlspecialchars($lib['library_initials']); ?></td>
                                        <td><?php echo htmlspecialchars($lib['library_location'] ?? 'N/A'); ?></td>
                                        <td>
                                            <a href="settings.php?tab=libraries&action=edit&id=<?php echo (int)$lib['library_id']; ?>" class="action-btn edit"><i class="fas fa-edit"></i> Edit</a>
                                            <a href="settings.php?tab=libraries&action=delete&id=<?php echo (int)$lib['library_id']; ?>" class="action-btn delete" onclick="return confirm('Are you sure you want to delete the library \'<?php echo htmlspecialchars($lib['library_name']); ?>\'? This action is irreversible and will affect all associated book copies and assigned librarians.');"><i class="fas fa-trash-alt"></i> Delete</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p style="text-align: center; color: var(--text-light); padding: 20px;">No libraries have been created yet. Please use the form above to add one.</p>
                <?php endif; ?>
            </div>
        </div>
        
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // Check if the current tab is 'general' before initializing general-settings specific elements
    if (document.getElementById('settingsForm')) {

        // 0. Loading Overlay Trigger
        document.getElementById('settingsForm').addEventListener('submit', function() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        });

        // 1. Live Image Preview Logic
        const logoInput = document.getElementById('logoFile');
        const previewImg = document.getElementById('imagePreview');
        const currentLogo = document.getElementById('currentLogo');
        const dropZone = document.getElementById('dropZone');

        logoInput.addEventListener('change', function(e) {
            const file = this.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    previewImg.src = event.target.result;
                    previewImg.style.display = 'block';
                    if(currentLogo) currentLogo.style.display = 'none'; // Hide old logo
                }
                reader.readAsDataURL(file);
            }
        });

        // 2. Drag and Drop Visual Feedback
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });

        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length) {
                logoInput.files = files; // Assign dropped file to input
                // Trigger change event manually to run preview logic
                const event = new Event('change');
                logoInput.dispatchEvent(event);
            }
        });

        // 3. Smart Initials Generator
        const instName = document.getElementById('instName');
        const instInitials = document.getElementById('instInitials');
        const autoGenBtn = document.getElementById('autoGenBtn');

        function generateInitials() {
            const name = instName.value;
            // Get the first letter of each word
            const initials = name.match(/\b\w/g) || [];
            instInitials.value = initials.join('').toUpperCase();
            // Visual flash effect
            instInitials.style.transition = 'background-color 0.2s';
            instInitials.style.backgroundColor = '#e0e7ff';
            setTimeout(() => instInitials.style.backgroundColor = '#f9fafb', 300);
        }

        autoGenBtn.addEventListener('click', generateInitials);

        // Auto-suggest if initials field is empty on name blur
        instName.addEventListener('blur', function() {
            if(instInitials.value === '') {
                generateInitials();
            }
        });

        // 4. Live Fine Calculator
        const fineInput = document.getElementById('fineAmount');
        const currSymbol = document.getElementById('currSymbol');
        const calcPreview = document.getElementById('calcPreview');

        function updateCalc() {
            const amount = parseFloat(fineInput.value) || 0;
            const symbol = currSymbol.value || '';
            const weekFine = (amount * 7).toFixed(2);
            calcPreview.textContent = `${symbol}${weekFine}`;
        }

        fineInput.addEventListener('input', updateCalc);
        currSymbol.addEventListener('input', updateCalc);
        
        // Run once on load
        updateCalc();
    }
});
</script>

<?php admin_footer(); close_db_connection($conn); ?>