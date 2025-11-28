<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

$member_id = $_SESSION['member_id'];
$book_id = (int)($_GET['book_id'] ?? 0);

if ($book_id === 0) {
    die("Invalid Book ID.");
}

// 1. Fetch Member Details
$stmt_mem = $conn->prepare("SELECT full_name, member_uid FROM tbl_members WHERE member_id = ?");
$stmt_mem->bind_param("i", $member_id);
$stmt_mem->execute();
$mem_data = $stmt_mem->get_result()->fetch_assoc();
$reader_name = htmlspecialchars($mem_data['full_name']);
$reader_id = htmlspecialchars($mem_data['member_uid']);

// 2. Fetch Book Details and Base UID
// Updated to fetch is_downloadable
$stmt = $conn->prepare("
    SELECT 
        tb.title, 
        tb.author, 
        tb.soft_copy_path, 
        tb.is_online_available,
        tb.security_control,
        tb.is_downloadable,
        tbc.book_uid as base_uid
    FROM 
        tbl_books tb
    LEFT JOIN
        tbl_book_copies tbc ON tb.book_id = tbc.book_id AND tbc.book_uid LIKE '%-BASE'
    WHERE 
        tb.book_id = ?
");

// --- Fetch Branding Details ---
$inst_name = get_setting($conn, 'institution_name') ?: 'Institution';
$lib_name = get_setting($conn, 'library_name') ?: 'LMS';
$logo_path_raw = get_setting($conn, 'institution_logo');
$logo_path = (!empty($logo_path_raw) && file_exists($logo_path_raw)) ? $logo_path_raw : null;

$stmt->bind_param("i", $book_id);
$stmt->execute();
$book_data = $stmt->get_result()->fetch_assoc();

if (!$book_data || $book_data['is_online_available'] != 1 || empty($book_data['soft_copy_path'])) {
    die("Online reading not available for this book.");
}

$book_title = htmlspecialchars($book_data['title']);
$book_author = htmlspecialchars($book_data['author']);
$raw_path = $book_data['soft_copy_path'];
$is_downloadable = (int)$book_data['is_downloadable'];

// --- DETECT FILE TYPE (Local vs External Link) ---
$is_external_url = (strpos($raw_path, 'http://') === 0 || strpos($raw_path, 'https://') === 0);
$display_path = '';

if ($is_external_url) {
    $display_path = $raw_path;
    
    // Automatic Google Drive Link Fixer (View -> Preview for Embedding)
    if (strpos($display_path, 'drive.google.com') !== false && strpos($display_path, '/view') !== false) {
        $display_path = str_replace('/view', '/preview', $display_path);
    }
} else {
    $display_path = htmlspecialchars($raw_path);
}

// Extract the base ID (remove the -BASE suffix)
$base_uid_raw = $book_data['base_uid'] ?? 'N/A';
$base_book_id = htmlspecialchars(substr($base_uid_raw, 0, -5)); 

$security_enabled = ($book_data['security_control'] === 'Yes'); 

// 3. Log reading activity
$conn->query("
    CREATE TABLE IF NOT EXISTS tbl_learnings (
        learning_id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        book_id INT NOT NULL,
        read_date DATETIME NOT NULL,
        FOREIGN KEY (member_id) REFERENCES tbl_members(member_id) ON DELETE CASCADE,
        FOREIGN KEY (book_id) REFERENCES tbl_books(book_id) ON DELETE CASCADE
    )
");

$today = date('Y-m-d');
$stmt_check = $conn->prepare("SELECT learning_id FROM tbl_learnings WHERE member_id = ? AND book_id = ? AND DATE(read_date) = ?");
$stmt_check->bind_param("iis", $member_id, $book_id, $today);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows === 0) {
    $stmt_log = $conn->prepare("INSERT INTO tbl_learnings (member_id, book_id, read_date) VALUES (?, ?, NOW())");
    $stmt_log->bind_param("ii", $member_id, $book_id);
    $stmt_log->execute();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Read: <?php echo $book_title; ?></title>
    
    <?php if (!$is_external_url): ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.10.377/pdf.worker.min.js';
    </script>
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* Reset & Layout */
        body, html { 
            margin: 0; padding: 0; height: 100%; 
            background-color: #525659; 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            overflow: hidden; 
            
            /* MOBILE/CONTENT SECURITY: Disable text selection and touch callouts */
            -webkit-user-select: none; 
            -moz-user-select: none;    
            -ms-user-select: none;     
            user-select: none;
            -webkit-touch-callout: none;
        }
        
        /* --- Main Reader Interface --- */
        #main-reader-interface {
            display: none; /* Hidden until agreement */
            height: 100%;
            flex-direction: column;
        }

        /* --- Header --- */
        .reader-header {
            background-color: #333; color: #fff; padding: 10px 20px;
            display: flex; justify-content: space-between; align-items: center;
            height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.3); z-index: 100;
        }
        
        /* BRANDING */
        .branding-section { 
            display: flex; align-items: center; gap: 10px;
            min-width: 200px; max-width: 300px;
        }
        .branding-section img {
            height: 40px; width: auto; filter: brightness(1.2);
        }
        .branding-text { 
            line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .branding-text .inst-name {
            font-size: 1.0rem; font-weight: 700; color: #4cc9f0;
        }
        .branding-text .lib-name {
            font-size: 0.8rem; color: #bbb;
        }

        .reader-info { font-size: 0.9rem; line-height: 1.3; text-align: right; }
        .reader-info strong { color: #4cc9f0; }
        .book-info { text-align: center; flex-grow: 1; padding: 0 20px; }
        .book-title { font-size: 1.1rem; font-weight: 600; display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .book-author { font-size: 0.85rem; color: #bbb; }
        .book-id-display { font-size: 0.75rem; color: #999; display: block; font-family: monospace; line-height: 1.2; margin-top: 2px;}
        
        .controls { display: flex; gap: 10px; align-items: center; }

        .btn {
            background: #444; color: white; border: 1px solid #666;
            padding: 5px 12px; border-radius: 4px; cursor: pointer;
            font-size: 14px; transition: 0.2s; text-decoration: none;
            display: inline-block; user-select: none;
            display: flex; align-items: center; justify-content: center;
        }
        .btn:hover { background: #666; }
        .btn i { pointer-events: none; }
        .btn-exit { border-color: #d9534f; color: #d9534f; }
        .btn-exit:hover { background: #d9534f; color: white; }
        
        .btn-fullscreen { border-color: #4cc9f0; color: #4cc9f0; }
        .btn-fullscreen:hover { background: #4cc9f0; color: #222; }

        /* --- PDF/Content Container --- */
        #content-container {
            height: calc(100vh - 60px);
            overflow-y: auto; overflow-x: hidden;
            text-align: center; padding: 0;
            background-color: #525659;
            user-select: none; -webkit-user-select: none;
            -webkit-touch-callout: none; 
            position: relative;
        }
        
        /* PDF Specific */
        .pdf-page { margin: 20px auto; box-shadow: 0 4px 15px rgba(0,0,0,0.4); display: block; background-color: white; }
        
        /* Iframe Specific */
        .external-frame { width: 100%; height: 100%; border: none; background: #fff; }
        
        .loading { color: white; font-size: 1.2rem; margin-top: 50px; }

        /* --- MODAL STYLES --- */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.9); backdrop-filter: blur(8px);
            z-index: 9999; display: flex; justify-content: center; align-items: center;
        }
        .modal-box {
            background: white; width: 90%; max-width: 650px;
            border-radius: 12px; overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.7);
            animation: popIn 0.3s cubic-bezier(0.18, 0.89, 0.32, 1.28);
        }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        /* User Manual Styles */
        .manual-header { background: #4361ee; color: white; padding: 20px; text-align: center; }
        .manual-body { padding: 30px; color: #333; }
        .manual-footer { padding: 20px; background: #f8f9fa; border-top: 1px solid #eee; display: flex; justify-content: space-between; }
        .rules-list { list-style: none; padding: 0; margin: 0; }
        .rules-list li { margin-bottom: 15px; display: flex; gap: 15px; }
        .icon-box { width: 35px; height: 35px; border-radius: 50%; display: flex; justify-content: center; align-items: center; flex-shrink: 0; }
        .icon-do { background: #d1fae5; color: #059669; }
        .icon-dont { background: #fee2e2; color: #dc2626; }
        .rule-text h4 { margin: 0 0 5px 0; font-size: 1rem; }
        .rule-text p { margin: 0; font-size: 0.9rem; color: #666; line-height: 1.4; }
        .effect-tag { display: inline-block; background: #ffe3e3; color: #d90429; padding: 2px 6px; border-radius: 4px; font-size: 0.75rem; font-weight: 700; margin-top: 5px; }

        /* Security Alert Styles */
        .alert-header { background: #d90429; color: white; padding: 20px; text-align: center; }
        .alert-body { padding: 30px; text-align: center; }
        .alert-icon { font-size: 4rem; color: #d90429; margin-bottom: 20px; animation: shake 0.5s; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-10px); } 75% { transform: translateX(10px); } }
        
        .breach-details { background: #f8f9fa; border: 1px solid #eee; border-radius: 8px; padding: 15px; text-align: left; margin: 20px 0; }
        .breach-details p { margin: 5px 0; font-size: 0.9rem; }
        .breach-details strong { color: #333; width: 100px; display: inline-block; }

        .resolution-box { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 8px; font-size: 0.9rem; margin-top: 20px; }
        
        /* Countdown Timer Style */
        .countdown-container { margin-top: 20px; font-weight: bold; color: #d90429; font-size: 1.1rem; }
        .countdown-clock { display: inline-block; background: #d90429; color: white; padding: 5px 10px; border-radius: 5px; margin-left: 5px; font-family: monospace; font-size: 1.2rem; }

        /* Buttons */
        .btn-agree { background: #4361ee; color: white; border: none; padding: 12px 30px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .btn-agree:hover { background: #3a56d4; }
        .btn-back { background: white; border: 2px solid #eee; color: #666; padding: 12px 30px; border-radius: 6px; font-weight: 600; text-decoration: none; }
        .btn-back:hover { border-color: #ccc; }

        @media print { body { display: none !important; } }
    </style>
</head>
<body oncontextmenu="return false;">

    <?php if ($security_enabled): ?>
    <div class="modal-overlay" id="manualModal">
        <div class="modal-box">
            <div class="manual-header">
                <h2 style="margin:0;"><i class="fas fa-book-reader"></i> Reader Manual & Guidelines</h2>
            </div>
            <div class="manual-body">
                <ul class="rules-list">
                    <li>
                        <div class="icon-box icon-do"><i class="fas fa-check"></i></div>
                        <div class="rule-text">
                            <h4>Do: Read & Interact</h4>
                            <p>Feel free to scroll, zoom, and enjoy the content.</p>
                        </div>
                    </li>
                    <li>
                        <div class="icon-box icon-do"><i class="fas fa-expand"></i></div>
                        <div class="rule-text">
                            <h4>Full Screen Mode</h4>
                            <p>For security and immersion, the book will open in full screen.</p>
                        </div>
                    </li>
                    <li>
                        <div class="icon-box icon-dont"><i class="fas fa-camera"></i></div>
                        <div class="rule-text">
                            <h4>Don't: Take Screenshots</h4>
                            <p>Using <strong>Print Screen</strong> or <strong>Shift + Win + S</strong> is strictly prohibited.</p>
                            <span class="effect-tag">Effect: Immediate Account Lock & Logout</span>
                        </div>
                    </li>
                    <li>
                        <div class="icon-box icon-dont"><i class="fas fa-copy"></i></div>
                        <div class="rule-text">
                            <h4>Don't: Attempt to Copy</h4>
                            <p>Right-click, text selection, and printing (Ctrl+P) are disabled.</p>
                        </div>
                    </li>
                </ul>
            </div>
            <div class="manual-footer">
                <a href="search.php" class="btn-back">Disagree & Exit</a>
                <button class="btn-agree" onclick="acceptAndEnter()">Agree & Continue Reading</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal-overlay" id="securityModal" style="display: none;">
        <div class="modal-box" style="border: 3px solid #d90429;">
            <div class="alert-header">
                <h2 style="margin:0;"><i class="fas fa-shield-alt"></i> SECURITY ALERT</h2>
            </div>
            <div class="alert-body">
                <i class="fas fa-exclamation-triangle alert-icon"></i>
                <h3 style="color: #d90429; margin-top:0;">Unauthorized Action Detected</h3>
                <p>You attempted to capture a screenshot or switch context inappropriately.</p>
                
                <div class="breach-details">
                    <p><strong>User:</strong> <?php echo $reader_name; ?> (<?php echo $reader_id; ?>)</p>
                    <p><strong>Book:</strong> <?php echo $book_title; ?></p>
                    <p><strong>Violation:</strong> Screenshot / Screen Capture Attempt</p>
                    <p><strong>Action:</strong> Account Locked & Logged Out</p>
                </div>

                <div class="resolution-box">
                    <strong><i class="fas fa-info-circle"></i> Resolution:</strong><br>
                    Your account has been deactivated. Contact the Library Administrator to reactivate your account.
                </div>

                <div class="countdown-container">
                    System Logout in: <span id="countdown-timer" class="countdown-clock">15</span> seconds
                </div>
            </div>
        </div>
    </div>

    <div id="main-reader-interface" style="<?php echo $security_enabled ? '' : 'display: flex;'; ?>">
        <div class="reader-header">
            <div class="branding-section">
                <?php if ($logo_path): ?>
                    <img src="<?php echo $logo_path; ?>" alt="Logo">
                <?php endif; ?>
                <div class="branding-text">
                    <div class="inst-name"><?php echo htmlspecialchars($inst_name); ?></div>
                    <div class="lib-name"><?php echo htmlspecialchars($lib_name); ?></div>
                </div>
            </div>
            <div class="book-info">
                <span class="book-title"><?php echo $book_title; ?></span>
                <span class="book-author">by <?php echo $book_author; ?></span>
                <span class="book-id-display">ID: <?php echo $base_book_id; ?></span> 
            </div>
            <div class="controls">
                <div class="reader-info" style="display: block; margin-right: 15px; text-align: right;">
                    <div>User: <strong><?php echo $reader_name; ?></strong></div>
                    <div>ID: <?php echo $reader_id; ?></div>
                </div>
                
                <?php if ($is_downloadable == 1): ?>
                    <a href="<?php echo $is_external_url ? $display_path : 'serve_pdf.php?id='.$book_id; ?>" 
                       <?php echo $is_external_url ? 'target="_blank"' : 'download="'.htmlspecialchars($book_title).'.pdf"'; ?> 
                       class="btn" title="Download Book">
                       <i class="fas fa-download"></i>
                    </a>
                <?php endif; ?>

                <button class="btn btn-fullscreen" onclick="toggleFullScreen()" title="Toggle Full Screen">
                    <i class="fas fa-expand"></i>
                </button>

                <?php if ($is_external_url): ?>
                    
                <?php else: ?>
                    <button class="btn" id="zoom-out"><i class="fas fa-minus"></i></button>
                    <span id="zoom-level" style="font-size: 0.9rem; min-width: 45px; text-align: center; color:#fff;">150%</span>
                    <button class="btn" id="zoom-in"><i class="fas fa-plus"></i></button>
                <?php endif; ?>
                
                <a href="search.php" class="btn btn-exit" onclick="isReaderActive = false">Exit</a>
            </div>
        </div>
        
        <div id="content-container">
            <?php if ($is_external_url): ?>
                <iframe src="<?php echo $display_path; ?>" class="external-frame" allowfullscreen></iframe>
            <?php else: ?>
                <div class="loading">Loading Document...</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const isExternal = <?php echo $is_external_url ? 'true' : 'false'; ?>;
        // If not external, construct the proxy url
        const url = isExternal ? '' : 'serve_pdf.php?id=<?php echo $book_id; ?>';
        
        let pdfDoc = null;
        let scale = 1.5;
        const container = document.getElementById('content-container');
        const zoomLevelDisplay = document.getElementById('zoom-level');
        let isReaderActive = <?php echo $security_enabled ? 'false' : 'true'; ?>;
        const securityEnabled = <?php echo $security_enabled ? 'true' : 'false'; ?>;
        
        // Track keys
        let metaPressed = false;
        let shiftPressed = false;
        let breachTriggered = false;
        
        // Flag to prevent false positives during full screen transitions
        let isFullscreenTransition = false;
        
        if (!securityEnabled && !isExternal) {
            // Load immediately if security is off and it's a local PDF
            loadDocument();
        }

        // --- 1. Full Screen Logic with Keyboard Lock ---
        
        ['fullscreenchange', 'webkitfullscreenchange', 'mozfullscreenchange', 'MSFullscreenChange'].forEach(evt => 
            document.addEventListener(evt, () => {
                isFullscreenTransition = true;
                setTimeout(() => { isFullscreenTransition = false; }, 1000);
            })
        );

        function toggleFullScreen() {
            isFullscreenTransition = true; 
            setTimeout(() => { isFullscreenTransition = false; }, 1000);

            if (!document.fullscreenElement && !document.mozFullScreenElement && 
                !document.webkitFullscreenElement && !document.msFullscreenElement) {
                
                const elem = document.documentElement;
                let requestPromise = null;

                if (elem.requestFullscreen) requestPromise = elem.requestFullscreen();
                else if (elem.msRequestFullscreen) elem.msRequestFullscreen();
                else if (elem.mozRequestFullScreen) elem.mozRequestFullScreen();
                else if (elem.webkitRequestFullscreen) elem.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);

                if (requestPromise) {
                    requestPromise.then(() => {
                        if (navigator.keyboard && navigator.keyboard.lock) {
                            navigator.keyboard.lock(['Escape']).catch((e) => {});
                        }
                    }).catch(err => { console.log("Full screen denied:", err); });
                }
            } else {
                if (navigator.keyboard && navigator.keyboard.unlock) navigator.keyboard.unlock();
                if (document.exitFullscreen) document.exitFullscreen();
                else if (document.msExitFullscreen) document.msExitFullscreen();
                else if (document.mozCancelFullScreen) document.mozCancelFullScreen();
                else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
            }
        }

        // --- 2. User Manual Logic ---
        function acceptAndEnter() {
            toggleFullScreen();
            document.getElementById('manualModal').style.opacity = '0';
            setTimeout(() => {
                document.getElementById('manualModal').style.display = 'none';
                document.getElementById('main-reader-interface').style.display = 'flex';
                isReaderActive = true;
                if (!isExternal) loadDocument(); 
            }, 300);
        }

        // --- 3. PDF Rendering Logic (Only if Local) ---
        function loadDocument() {
            if (isExternal) return; // Do nothing if external
            
            pdfjsLib.getDocument(url).promise.then(pdf => {
                pdfDoc = pdf;
                renderAllPages();
            }).catch(err => {
                container.innerHTML = '<p class="loading" style="color:#ef233c;">Error loading document.</p>';
            });
        }

        function renderAllPages() {
            if (isExternal) return;
            container.innerHTML = '';
            if(zoomLevelDisplay) zoomLevelDisplay.textContent = Math.round(scale * 100) + '%';
            for (let num = 1; num <= pdfDoc.numPages; num++) { renderPage(num); }
        }

        function renderPage(num) {
            pdfDoc.getPage(num).then(page => {
                const viewport = page.getViewport({ scale: scale });
                const outputScale = Math.max(window.devicePixelRatio || 1, 2); 

                const canvas = document.createElement('canvas');
                canvas.className = 'pdf-page';
                canvas.width = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width = Math.floor(viewport.width) + "px";
                canvas.style.height = Math.floor(viewport.height) + "px";

                container.appendChild(canvas);

                const transform = [outputScale, 0, 0, outputScale, 0, 0];
                const renderContext = {
                    canvasContext: canvas.getContext('2d'),
                    transform: transform,
                    viewport: viewport
                };
                page.render(renderContext);
            });
        }

        if (!isExternal) {
            document.getElementById('zoom-in').addEventListener('click', () => { if (scale < 4.0) { scale += 0.25; renderAllPages(); } });
            document.getElementById('zoom-out').addEventListener('click', () => { if (scale > 0.5) { scale -= 0.25; renderAllPages(); } });
        }

        // --- 4. Security Logic ---
        function triggerSecurityBreach() {
            if (!securityEnabled) return; 
            if (breachTriggered) return; 
            breachTriggered = true;

            if (navigator.keyboard && navigator.keyboard.unlock) navigator.keyboard.unlock();
            if (document.exitFullscreen) { document.exitFullscreen().catch(err => {}); }

            // 1. UI Updates
            const manualModal = document.getElementById('manualModal');
            if(manualModal) manualModal.style.display = 'none';
            
            document.getElementById('main-reader-interface').style.filter = 'blur(10px)';
            document.getElementById('securityModal').style.display = 'flex';

            // 2. Backend Call
            fetch('security_action.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    action: 'log_breach', 
                    book_id: <?php echo $book_id; ?> 
                })
            });

            // 3. Countdown Timer
            let timeLeft = 15;
            const timerElement = document.getElementById('countdown-timer');
            
            const countdownInterval = setInterval(() => {
                timeLeft--;
                if(timerElement) timerElement.textContent = timeLeft;
                
                if (timeLeft <= 0) {
                    clearInterval(countdownInterval);
                    window.location.href = 'logout.php';
                }
            }, 1000);
        }

        // -- Key Tracking --
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Meta' || e.key === 'OS') metaPressed = true;
            if (e.key === 'Shift') shiftPressed = true;

            // 1. Detect Shift + Win + S
            if (metaPressed && shiftPressed && (e.key.toLowerCase() === 's' || e.code === 'KeyS')) {
                e.preventDefault();
                triggerSecurityBreach();
            }

            // 2. Disable Standard Shortcuts
            if ((e.ctrlKey || e.metaKey) && (['p', 's', 'c', 'u'].includes(e.key.toLowerCase()))) {
                e.preventDefault();
            }

            if (e.key === 'Escape') { e.preventDefault(); }
        });

        document.addEventListener('keyup', function(e) {
            if (e.key === 'Meta' || e.key === 'OS') metaPressed = false;
            if (e.key === 'Shift') shiftPressed = false;
            
            // 3. Detect Print Screen Key
            if (e.key === 'PrintScreen') {
                navigator.clipboard.writeText(''); 
                triggerSecurityBreach();
            }
        });

        // -- Mobile/App Switching Detection --
        document.addEventListener('visibilitychange', function() {
            if (document.hidden && isReaderActive && !isFullscreenTransition) {
                console.log("Visibility change detected.");
                triggerSecurityBreach();
            }
        });

        // -- Reset Keys on Focus --
        window.addEventListener('focus', function() {
            metaPressed = false;
            shiftPressed = false;
        });

        // -- Focus Loss Detection --
        window.addEventListener('blur', function() {
            if (metaPressed && shiftPressed && isReaderActive) {
                triggerSecurityBreach();
            }
        });

    </script>
</body>
</html>
<?php close_db_connection($conn); ?>