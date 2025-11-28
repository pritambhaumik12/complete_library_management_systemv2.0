<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

$message = '';
$error = '';

// Check for flash message from redirect
if (isset($_SESSION['flash_success'])) {
    $message = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

// --- Fetch Content Types ---
$content_types = [];
$ct_result = $conn->query("SELECT * FROM tbl_content_types ORDER BY type_name ASC");
while($row = $ct_result->fetch_assoc()) $content_types[] = $row;

// --- Fetch Libraries (For Super Admin) ---
$libraries = [];
if (is_super_admin($conn)) {
    $lib_result = $conn->query("SELECT * FROM tbl_libraries ORDER BY library_name ASC");
    while($row = $lib_result->fetch_assoc()) $libraries[] = $row;
}

// --- Helper Function for Book File Upload ---
function handle_book_upload($file_input_name) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES[$file_input_name]['tmp_name'];
        $fileName = $_FILES[$file_input_name]['name'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
        
        $allowedfileExtensions = array('pdf', 'epub');
        
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $uploadFileDir = '../uploads/books/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0777, true);
            }
            
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $dest_path = $uploadFileDir . $newFileName;
            
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                return 'uploads/books/' . $newFileName; 
            }
        }
    }
    return null;
}

// --- Helper Function for Cover Image Upload ---
function handle_cover_upload($file_input_name) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES[$file_input_name]['tmp_name'];
        $fileName = $_FILES[$file_input_name]['name'];
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));
        
        $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'webp');
        
        if (in_array($fileExtension, $allowedfileExtensions)) {
            $uploadFileDir = '../uploads/covers/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0777, true);
            }
            
            $newFileName = md5(time() . $fileName) . '.' . $fileExtension;
            $dest_path = $uploadFileDir . $newFileName;
            
            if(move_uploaded_file($fileTmpPath, $dest_path)) {
                return 'uploads/covers/' . $newFileName; 
            }
        }
    }
    return null;
}

// --- Helper to Generate Random Alphanumeric String ---
function generate_random_code($length = 6) {
    $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $res = '';
    for ($i = 0; $i < $length; $i++) {
        $res .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    return $res;
}

// --- Handle Book Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_content_type') {
        $new_type = trim($_POST['new_type_name'] ?? '');
        if (!empty($new_type)) {
            $stmt = $conn->prepare("INSERT INTO tbl_content_types (type_name) VALUES (?)");
            $stmt->bind_param("s", $new_type);
            if ($stmt->execute()) {
                $message = "Content Type added successfully.";
                // Refresh content types
                $content_types = [];
                $ct_result = $conn->query("SELECT * FROM tbl_content_types ORDER BY type_name ASC");
                while($row = $ct_result->fetch_assoc()) $content_types[] = $row;
            } else {
                $error = "Error adding content type (might already exist).";
            }
        }
    } elseif ($action === 'add_book') {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        
        // --- Handle Book Source (Upload vs URL) ---
        $ebook_method = $_POST['ebook_method'] ?? 'upload';
        $soft_copy_path = '';
        
        if ($ebook_method === 'upload') {
            $soft_copy_path = handle_book_upload('soft_copy_file');
        } elseif ($ebook_method === 'url') {
            $soft_copy_path = trim($_POST['soft_copy_url'] ?? '');
        }

        // --- Handle Cover Image ---
        $cover_method = $_POST['cover_method'] ?? 'upload';
        $cover_image_path = '';
        
        if ($cover_method === 'upload') {
            $cover_image_path = handle_cover_upload('cover_image_file');
        } else {
            $cover_image_path = trim($_POST['cover_image_url'] ?? '');
        }
        
        $isbn = trim($_POST['isbn'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $shelf_location = trim($_POST['shelf_location'] ?? '');
        $price = trim($_POST['price'] ?? ''); 
        $edition = trim($_POST['edition'] ?? '');
        $publication = trim($_POST['publication'] ?? '');
        $content_type_id = (int)($_POST['content_type_id'] ?? 0);
        $security_control = $_POST['security_control'] ?? 'No';
        
        // --- NEW: Downloadable Logic ---
        // Only allow downloadable if security is 'No'.
        $is_downloadable = (int)($_POST['is_downloadable'] ?? 0);
        if ($security_control === 'Yes') {
            $is_downloadable = 0;
        }

        // --- Determine Library ID ---
        $library_id = 0;
        if (is_super_admin($conn)) {
            $library_id = (int)($_POST['library_id'] ?? 0);
        } else {
            $admin_id = $_SESSION['admin_id'];
            $stmt_lib = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
            $stmt_lib->bind_param("i", $admin_id);
            $stmt_lib->execute();
            $res_lib = $stmt_lib->get_result()->fetch_assoc();
            $library_id = $res_lib['library_id'] ?? 0;
        }
        
        $book_type = $_POST['book_type'] ?? 'Physical';
        $quantity_physical = (int)($_POST['quantity'] ?? 0);
        
        $is_online_available = 0;
        $final_quantity = 0;
        $final_soft_copy = $soft_copy_path ?? '';
        
        if ($book_type === 'E-Book') {
            $final_quantity = 0;
            $is_online_available = !empty($final_soft_copy) ? 1 : 0;
        } elseif ($book_type === 'Physical') {
            $final_quantity = $quantity_physical;
            $is_online_available = 0;
            $final_soft_copy = ''; // Clear path if physical only
            $is_downloadable = 0; // Logic reset for physical
        } elseif ($book_type === 'Both') {
            $final_quantity = $quantity_physical;
            $is_online_available = !empty($final_soft_copy) ? 1 : 0;
        }

        // VALIDATION LOGIC
        if (empty($title) || empty($author) || empty($category) || empty($price)) {
            $error = "Title, Author, Category, and Price are required.";
        } elseif (($book_type === 'Physical' || $book_type === 'Both') && empty($shelf_location)) {
            $error = "Shelf Location is required for Physical books.";
        } elseif (($book_type === 'Physical' || $book_type === 'Both') && $final_quantity <= 0) {
            $error = "Physical quantity must be greater than 0 for 'Physical' or 'Both' types.";
        } elseif (($book_type === 'E-Book' || $book_type === 'Both') && empty($final_soft_copy)) {
            $error = "E-Book file or URL is required for 'E-Book' or 'Both' types.";
        } elseif ($library_id == 0) {
            $error = "Library assignment is required.";
        } else {
            // Updated INSERT query with is_downloadable
            $stmt = $conn->prepare("INSERT INTO tbl_books (title, author, edition, publication, soft_copy_path, is_online_available, isbn, category, total_quantity, available_quantity, shelf_location, library_id, content_type_id, security_control, is_downloadable, price, cover_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            // Added 'i' to types string for is_downloadable
            $stmt->bind_param("sssssisssissisids", $title, $author, $edition, $publication, $final_soft_copy, $is_online_available, $isbn, $category, $final_quantity, $final_quantity, $shelf_location, $library_id, $content_type_id, $security_control, $is_downloadable, $price, $cover_image_path);
            
            if ($stmt->execute()) {
                $book_id = $conn->insert_id;
                $success = true;

                $inst_init = get_setting($conn, 'institution_initials') ?: 'INS';
                
                $stmt_lib = $conn->prepare("SELECT library_initials FROM tbl_libraries WHERE library_id = ?");
                $stmt_lib->bind_param("i", $library_id);
                $stmt_lib->execute();
                $lib_row = $stmt_lib->get_result()->fetch_assoc();
                $lib_init = $lib_row ? $lib_row['library_initials'] : 'LIB';
                
                // --- GENERATE UNIQUE BASE UID ---
                $base_uid = '';
                do {
                    $unique_code = generate_random_code(6);
                    $base_uid = strtoupper($inst_init . '/' . $lib_init . '/' . $unique_code);
                    $check_term = $base_uid . '%';
                    $stmt_check = $conn->prepare("SELECT copy_id FROM tbl_book_copies WHERE book_uid LIKE ? LIMIT 1");
                    $stmt_check->bind_param("s", $check_term);
                    $stmt_check->execute();
                    $collision = $stmt_check->get_result()->num_rows > 0;
                } while ($collision);

                // 1. Insert Base Copy
                $base_copy_uid = $base_uid . "-BASE";
                $stmt_copy = $conn->prepare("INSERT INTO tbl_book_copies (book_id, book_uid, status) VALUES (?, ?, 'Base')"); 
                $stmt_copy->bind_param("is", $book_id, $base_copy_uid);
                if (!$stmt_copy->execute()) {
                    $success = false;
                }

                // 2. Insert physical copies
                for ($i = 1; $i <= $final_quantity; $i++) {
                    $book_uid = $base_uid . "-" . $i; 
                    $stmt_copy = $conn->prepare("INSERT INTO tbl_book_copies (book_id, book_uid) VALUES (?, ?)");
                    $stmt_copy->bind_param("is", $book_id, $book_uid);
                    if (!$stmt_copy->execute()) {
                        $success = false;
                        break;
                    }
                }

                if ($success) {
                    $_SESSION['flash_success'] = "Book added successfully (Base ID: {$base_uid}).";
                    redirect('books.php'); 
                } else {
                    $error = "Book added, but failed to generate copies.";
                }
            } else {
                $error = "Error adding book: " . $conn->error;
            }
        }
    } elseif ($action === 'edit_book') {
        $book_id = (int)($_POST['book_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        
        // --- Handle Book Source (Upload vs URL vs Keep) ---
        $ebook_method = $_POST['ebook_method'] ?? 'keep';
        $uploaded_path = null;

        if ($ebook_method === 'upload') {
            $uploaded_path = handle_book_upload('soft_copy_file');
        } elseif ($ebook_method === 'url') {
            $uploaded_path = trim($_POST['soft_copy_url'] ?? '');
        }
        
        // --- Handle Cover Image ---
        $cover_method = $_POST['cover_method'] ?? 'keep';
        $new_cover_path = null;
        
        if ($cover_method === 'upload') {
            $new_cover_path = handle_cover_upload('cover_image_file');
        } elseif ($cover_method === 'url') {
            $url_input = trim($_POST['cover_image_url'] ?? '');
            if (!empty($url_input)) {
                $new_cover_path = $url_input;
            }
        }
        
        $isbn = trim($_POST['isbn'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $shelf_location = trim($_POST['shelf_location'] ?? '');
        $price = trim($_POST['price'] ?? ''); 
        $edition = trim($_POST['edition'] ?? '');
        $publication = trim($_POST['publication'] ?? '');
        
        $book_type = $_POST['book_type'] ?? 'Physical';
        $quantity_physical = (int)($_POST['quantity'] ?? 0);
        $content_type_id = (int)($_POST['content_type_id'] ?? 0);
        $security_control = $_POST['security_control'] ?? 'No';
        
        // --- NEW: Downloadable Logic ---
        $is_downloadable = (int)($_POST['is_downloadable'] ?? 0);
        if ($security_control === 'Yes') {
            $is_downloadable = 0;
        }
        
        $stmt_current = $conn->prepare("SELECT soft_copy_path, total_quantity, available_quantity, cover_image FROM tbl_books WHERE book_id = ?");
        $stmt_current->bind_param("i", $book_id);
        $stmt_current->execute();
        $current_book_data = $stmt_current->get_result()->fetch_assoc();
        $current_soft_copy_path = $current_book_data['soft_copy_path'] ?? '';
        $current_cover = $current_book_data['cover_image'] ?? '';
        
        $final_cover_path = ($new_cover_path !== null) ? $new_cover_path : $current_cover;
        
        // Determine final soft copy path
        $final_soft_copy_path = ($ebook_method === 'keep') ? $current_soft_copy_path : $uploaded_path;
        
        $is_online_available = 0;
        $final_quantity = 0;
        
        if ($book_type === 'E-Book') {
            $final_quantity = 0;
            $is_online_available = !empty($final_soft_copy_path) ? 1 : 0;
        } elseif ($book_type === 'Physical') {
            $final_quantity = $quantity_physical;
            $is_online_available = 0;
            $final_soft_copy_path = '';
            $is_downloadable = 0; // Reset
        } elseif ($book_type === 'Both') {
            $final_quantity = $quantity_physical;
            $is_online_available = !empty($final_soft_copy_path) ? 1 : 0;
        }

        if (empty($title) || empty($author) || empty($category) || empty($price)) {
            $error = "Title, Author, Category and Price are required.";
        } elseif (($book_type === 'Physical' || $book_type === 'Both') && empty($shelf_location)) {
            $error = "Shelf location is required for physical books.";
        } elseif (($book_type === 'Physical' || $book_type === 'Both') && $final_quantity <= 0) {
            $error = "Physical quantity must be greater than 0 for 'Physical' or 'Both' types.";
        } elseif (($book_type === 'E-Book' || $book_type === 'Both') && empty($final_soft_copy_path)) {
            $error = "E-Book file or URL is required for 'E-Book' or 'Both' types.";
        } else {
            $quantity_diff = $final_quantity - $current_book_data['total_quantity'];
            $new_available_quantity = max(0, $current_book_data['available_quantity'] + $quantity_diff);
            
            // Updated UPDATE query with is_downloadable
            $stmt = $conn->prepare("UPDATE tbl_books SET title = ?, author = ?, edition = ?, publication = ?, soft_copy_path = ?, is_online_available = ?, isbn = ?, category = ?, shelf_location = ?, total_quantity = ?, available_quantity = ?, content_type_id = ?, security_control = ?, is_downloadable = ?, price = ?, cover_image = ? WHERE book_id = ?");
            
            // Added 'i' to types string
            $stmt->bind_param("sssssisssissisidi", $title, $author, $edition, $publication, $final_soft_copy_path, $is_online_available, $isbn, $category, $shelf_location, $final_quantity, $new_available_quantity, $content_type_id, $security_control, $is_downloadable, $price, $final_cover_path, $book_id);
            
            if ($stmt->execute()) {
                $message = "Book details updated successfully.";
                $_SESSION['flash_success'] = $message; 
                redirect('books.php');
                
                // --- AUTO-GENERATE COPIES ---
                if (($book_type === 'Physical' || $book_type === 'Both') && $final_quantity > 0) {
                    $stmt_base = $conn->prepare("SELECT book_uid FROM tbl_book_copies WHERE book_id = ? AND book_uid LIKE '%-BASE' LIMIT 1");
                    $stmt_base->bind_param("i", $book_id);
                    $stmt_base->execute();
                    $base_res = $stmt_base->get_result()->fetch_assoc();
                    
                    if ($base_res) {
                        $base_uid_full = $base_res['book_uid'];
                        $base_uid_prefix = substr($base_uid_full, 0, -5); 
                        
                        $stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM tbl_book_copies WHERE book_id = ? AND book_uid NOT LIKE '%-BASE'");
                        $stmt_count->bind_param("i", $book_id);
                        $stmt_count->execute();
                        $existing_count = $stmt_count->get_result()->fetch_assoc()['count'];
                        
                        if ($final_quantity > $existing_count) {
                            $needed = $final_quantity - $existing_count;
                            $stmt_max = $conn->prepare("SELECT book_uid FROM tbl_book_copies WHERE book_id = ? AND book_uid NOT LIKE '%-BASE'");
                            $stmt_max->bind_param("i", $book_id);
                            $stmt_max->execute();
                            $res_max = $stmt_max->get_result();
                            
                            $max_suffix = 0;
                            while($row = $res_max->fetch_assoc()) {
                                $parts = explode('-', $row['book_uid']);
                                $suffix = (int)end($parts);
                                if ($suffix > $max_suffix) $max_suffix = $suffix;
                            }
                            
                            for ($i = 1; $i <= $needed; $i++) {
                                $next_suffix = $max_suffix + $i;
                                $new_uid = $base_uid_prefix . "-" . $next_suffix;
                                $stmt_ins = $conn->prepare("INSERT INTO tbl_book_copies (book_id, book_uid) VALUES (?, ?)");
                                $stmt_ins->bind_param("is", $book_id, $new_uid);
                                $stmt_ins->execute();
                            }
                        }
                    }
                }
            } else {
                $error = "Error updating book: " . $conn->error;
            }
        }
    } elseif ($action === 'delete_book') {
        $book_id = (int)($_POST['book_id'] ?? 0);
        
        $stmt_check = $conn->prepare("SELECT COUNT(tbc.copy_id) AS issued_count FROM tbl_book_copies tbc JOIN tbl_circulation tc ON tbc.copy_id = tc.copy_id WHERE tbc.book_id = ? AND tc.status = 'Issued'");
        $stmt_check->bind_param("i", $book_id);
        $stmt_check->execute();
        $issued_count = $stmt_check->get_result()->fetch_assoc()['issued_count'];

        if ($issued_count > 0) {
            $error = "Cannot delete book. {$issued_count} copies are currently issued.";
        } else {
            $conn->begin_transaction();
            try {
                $stmt_copy_del = $conn->prepare("DELETE FROM tbl_book_copies WHERE book_id = ?");
                $stmt_copy_del->bind_param("i", $book_id);
                $stmt_copy_del->execute();

                $stmt_book_del = $conn->prepare("DELETE FROM tbl_books WHERE book_id = ?");
                $stmt_book_del->bind_param("i", $book_id);
                $stmt_book_del->execute();
                
                $conn->commit();
                $_SESSION['flash_success'] = "Book and its copies deleted successfully.";
                redirect('books.php');
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Transaction failed: Error deleting book/copies. " . $e->getMessage();
            }
        }
    }
}

// --- Fetch Books for View/Search ---
$search_query = trim($_GET['search'] ?? '');
// Added is_downloadable to selection
$sql = "SELECT b.*, l.library_name, c.type_name 
        FROM tbl_books b 
        LEFT JOIN tbl_libraries l ON b.library_id = l.library_id 
        LEFT JOIN tbl_content_types c ON b.content_type_id = c.content_type_id";
$params = [];
$types = '';

if (!is_super_admin($conn)) {
    $admin_id = $_SESSION['admin_id'];
    $stmt_lib = $conn->prepare("SELECT library_id FROM tbl_admin WHERE admin_id = ?");
    $stmt_lib->bind_param("i", $admin_id);
    $stmt_lib->execute();
    $my_lib_id = $stmt_lib->get_result()->fetch_assoc()['library_id'] ?? 0;
    
    $sql .= " WHERE b.library_id = ?";
    $params[] = $my_lib_id;
    $types .= 'i';
} else {
    $sql .= " WHERE 1=1"; 
}

if (!empty($search_query)) {
    $sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.category LIKE ? OR l.library_name LIKE ? OR c.type_name LIKE ?)";
    $search_term = "%" . $search_query . "%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssssss';
}

$sql .= " ORDER BY b.book_id DESC";
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$books_result = $stmt->get_result();

admin_header('Book Management');
?>

<style>
    /* Glass Card & General Layout */
    .glass-card {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.5);
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        margin-bottom: 30px;
        color: #111827; 
    }

    .card-header {
        display: flex;
        align-items: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }

    .card-header h2 {
        margin: 0;
        font-size: 1.5rem;
        color: #000; 
        display: flex; align-items: center; gap: 10px;
    }

    /* Grid Form */
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .form-group { position: relative; margin-bottom: 5px; }
    
    .form-group label {
        display: block;
        font-size: 0.85rem;
        font-weight: 600;
        color: #111827; 
        margin-bottom: 5px;
    }

    .input-wrapper { position: relative; }
    
    .input-wrapper i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #4b5563; 
        pointer-events: none;
    }

    .form-control {
        width: 85%;
        padding: 12px 12px 12px 40px;
        border: 1px solid rgba(0,0,0,0.1);
        background: rgba(255,255,255,0.9); 
        border-radius: 10px;
        font-size: 0.95rem;
        color: #111827; 
        transition: all 0.3s;
    }
    .form-control::placeholder {
        color: #6b7280; 
    }

    .form-control:focus {
        background: #fff;
        border-color: #4f46e5; 
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        outline: none;
    }

    /* Custom File Input */
    .file-upload-box {
        border: 2px dashed #9ca3af; 
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        background: rgba(255, 255, 255, 0.8);
        cursor: pointer;
        transition: 0.2s;
        height: 50%;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        color: #111827;
    }
    .file-upload-box:hover { border-color: #4f46e5; background: rgba(243, 244, 246, 0.5); }
    
    /* Buttons */
    .btn-main {
        background: linear-gradient(135deg, #4f46e5, #3730a3); 
        color: white;
        border: none;
        padding: 12px 25px;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        transition: transform 0.2s;
        display: inline-flex; align-items: center; gap: 8px; justify-content: center;
        width: 100%;
    }
    .btn-main:hover { transform: translateY(-2px); }

    .btn-action {
        padding: 8px 12px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        transition: 0.2s;
        color: white;
        font-size: 0.875rem;
    }
    .btn-edit { background: #1d4ed8; box-shadow: 0 2px 5px rgba(29, 78, 216, 0.3); } 
    .btn-del { background: #b91c1c; box-shadow: 0 2px 5px rgba(185, 28, 28, 0.3); } 
    .btn-view { background: #059669; text-decoration: none; display: inline-block; font-size: 0.85rem; padding: 5px 10px; border-radius: 6px; color: white;}
    
    /* Search Button */
    .btn-search-dark { background: #374151; }

    /* Table Styles */
    .table-container { overflow-x: auto; border-radius: 12px; }
    .glass-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        background: rgba(255,255,255,0.5); 
    }
    .glass-table th {
        background: rgba(79, 70, 229, 0.1); 
        color: #111827; 
        font-weight: 700;
        padding: 15px;
        text-align: left;
    }
    .glass-table td {
        padding: 15px;
        border-bottom: 1px solid rgba(0,0,0,0.1);
        color: #111827; 
    }
    .glass-table tr:hover { background: rgba(255,255,255,0.7); }
    .glass-table tbody tr:last-child td { border-bottom: none; }

    /* Modal Styling */
    .modal {
        display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%;
        background-color: rgba(17, 24, 39, 0.7); backdrop-filter: blur(5px);
        justify-content: center; align-items: center;
    }
    .modal-content {
        background: #fff;
        padding: 30px;
        border-radius: 20px;
        width: 90%;
        height: 70%;    
        overflow-y: auto;
        max-width: 600px;
        position: relative;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2), 0 10px 10px -5px rgba(0, 0, 0, 0.08);
        animation: slideUp 0.3s ease-out;
    }
    
    /* Alerts */
    .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 600; }
    .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
    .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
</style>

<div class="glass-card">
    <div class="card-header">
        <h2><i class="fas fa-plus-circle" style="color: #4f46e5;"></i> Add New Book</h2>
    </div>
    
    <?php if ($message): ?> <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $message; ?></div> <?php endif; ?>
    <?php if ($error): ?> <div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div> <?php endif; ?>
    
    <form method="POST" class="form-grid" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_book">
        
        <div class="form-group">
            <label>Book Title</label>
            <div class="input-wrapper">
                <i class="fas fa-heading"></i>
                <input type="text" name="title" class="form-control" placeholder="Enter title" required>
            </div>
        </div>

        <?php if(is_super_admin($conn)): ?>
        <div class="form-group">
            <label>Library</label>
            <div class="input-wrapper">
                <i class="fas fa-university"></i>
                <select name="library_id" class="form-control" required>
                    <option value="">Select Library</option>
                    <?php foreach($libraries as $lib): ?>
                        <option value="<?php echo $lib['library_id']; ?>"><?php echo htmlspecialchars($lib['library_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <div class="form-group">
            <label>Author</label>
            <div class="input-wrapper">
                <i class="fas fa-user-pen"></i>
                <input type="text" name="author" class="form-control" placeholder="Enter author name" required>
            </div>
        </div>

        <div class="form-group">
            <label>Edition</label>
            <div class="input-wrapper">
                <i class="fas fa-bookmark"></i>
                <input type="text" name="edition" class="form-control" placeholder="e.g. 2nd Edition">
            </div>
        </div>

        <div class="form-group">
            <label>Publication (Publisher)</label>
            <div class="input-wrapper">
                <i class="fas fa-building"></i>
                <input type="text" name="publication" class="form-control" placeholder="e.g. Penguin Books">
            </div>
        </div>

        <div class="form-group">
            <label>ISBN (Optional)</label>
            <div class="input-wrapper">
                <i class="fas fa-barcode"></i>
                <input type="text" name="isbn" class="form-control" placeholder="e.g. 978-3-16-148410-0">
            </div>
        </div>

        <div class="form-group">
            <label>Category</label>
            <div class="input-wrapper">
                <i class="fas fa-tag"></i>
                <input type="text" name="category" class="form-control" placeholder="e.g. Science, Fiction" required>
            </div>
        </div>

        <div class="form-group">
            <label>Price</label>
            <div class="input-wrapper">
                <i class="fas fa-indian-rupee-sign"></i>
                <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required>
            </div>
        </div>

        <div class="form-group" style="grid-column: 1 / -1;">
            <label>Cover Image (Optional)</label>
            <div style="background: rgba(255,255,255,0.5); padding: 15px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.1);">
                <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="cover_method" value="upload" checked onclick="toggleCoverMethod('upload')"> 
                        Upload Image
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="cover_method" value="url" onclick="toggleCoverMethod('url')"> 
                        Select from Web
                    </label>
                </div>

                <div id="coverUploadDiv">
                    <input type="file" name="cover_image_file" class="form-control" accept="image/*">
                </div>

                <div id="coverUrlDiv" style="display: none;">
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div class="input-wrapper" style="flex-grow: 1;">
                            <i class="fas fa-link"></i>
                            <input type="url" id="coverUrlInput" name="cover_image_url" class="form-control" placeholder="Paste image link here..." oninput="previewCoverUrl()">
                        </div>
                        <button type="button" class="btn-action btn-search-dark" onclick="openGoogleImages()" title="Search Google Images">
                            <i class="fab fa-google"></i> Search
                        </button>
                    </div>
                    <div id="urlPreview" style="margin-top: 10px; display: none;">
                        <img id="urlPreviewImg" src="" alt="Preview" style="max-height: 150px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.2);">
                    </div>
                </div>
            </div>
        </div>

        <div class="form-group">
            <label>Content Type</label>
            <div style="display: flex; gap: 10px;">
                <div class="input-wrapper" style="flex: 1;">
                    <i class="fas fa-file-alt"></i>
                    <select name="content_type_id" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach($content_types as $ct): ?>
                            <option value="<?php echo $ct['content_type_id']; ?>"><?php echo htmlspecialchars($ct['type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn-action btn-edit" onclick="document.getElementById('addContentTypeModal').style.display='flex'" title="Add New Type"><i class="fas fa-plus"></i></button>
            </div>
        </div>
        
        <div class="form-group" id="shelfLocationGroup">
            <label>Shelf Location</label>
            <div class="input-wrapper">
                <i class="fas fa-map-marker-alt"></i>
                <input type="text" id="shelf_location" name="shelf_location" class="form-control" placeholder="e.g. A-12, Row 3">
            </div>
        </div>
        
        <div class="form-group">
            <label>Book Type</label>
            <div class="input-wrapper">
                <i class="fas fa-list-alt"></i>
                <select id="bookType" name="book_type" class="form-control" required style="padding-left: 40px; width: 100%;">
                    <option value="Physical">Physical Only</option>
                    <option value="E-Book">E-Book Only</option>
                    <option value="Both">Physical + E-Book</option>
                </select>
            </div>
        </div>
        
        <div id="securityGroup" class="form-group" style="display: none;">
            <label>Add Security Control?</label>
            <div class="input-wrapper">
                <i class="fas fa-shield-alt"></i>
                <select name="security_control" id="security_control" class="form-control" onchange="toggleFields()">
                    <option value="No">No (Standard Access)</option>
                    <option value="Yes">Yes (Protected Privacy)</option>
                </select>
            </div>
            <small style="color: #6b7280; font-size: 0.8rem;">If Yes, privacy control will be protected in read_online.</small>
        </div>

        <div id="downloadGroup" class="form-group" style="display: none;">
            <label>Allow Download?</label>
            <div class="input-wrapper">
                <i class="fas fa-download"></i>
                <select name="is_downloadable" class="form-control">
                    <option value="0">No</option>
                    <option value="1">Yes</option>
                </select>
            </div>
            <small style="color: #6b7280; font-size: 0.8rem;">Visible only if Security Control is 'No'.</small>
        </div>
        
        <div id="physicalGroup" class="form-group">
            <label>Physical Copies</label>
            <div class="input-wrapper">
                <i class="fas fa-copy"></i>
                <input type="number" id="physicalQuantity" name="quantity" class="form-control" placeholder="1" required min="1">
            </div>
        </div>

        <div id="ebookGroup" class="form-group" style="display: none; grid-column: 1 / -1;">
            <label>E-Book Source</label>
            <div style="background: rgba(255,255,255,0.5); padding: 15px; border-radius: 10px; border: 1px solid rgba(0,0,0,0.1);">
                <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="ebook_method" value="upload" checked onclick="toggleEbookMethod('upload')"> 
                        Upload File
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="radio" name="ebook_method" value="url" onclick="toggleEbookMethod('url')"> 
                        External Link
                    </label>
                </div>

                <div id="ebookUploadDiv">
                    <div class="file-upload-box" id="uploadBox">
                        <input type="file" id="fileInput" name="soft_copy_file" accept=".pdf,.epub" style="display: none;">
                        <div id="uploadMessage">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 1.5rem; color: #4b5563;"></i>
                            <div style="margin-top: 5px; font-size: 0.9rem; color: #111827;">Click to upload file</div>
                        </div>
                        <div id="filePreview" style="display: none; padding: 10px;">
                            <i class="fas fa-file-pdf" style="font-size: 2rem; color: #b91c1c;"></i>
                            <div id="fileName" style="font-size: 0.8rem; color: #111827; margin-top: 5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; max-width: 100%;"></div>
                        </div>
                    </div>
                </div>

                <div id="ebookUrlDiv" style="display: none;">
                    <div class="input-wrapper">
                        <i class="fas fa-link"></i>
                        <input type="url" name="soft_copy_url" id="soft_copy_url" class="form-control" placeholder="https://example.com/book.pdf">
                    </div>
                </div>
            </div>
        </div>

        
        <div style="grid-column: 1 / -1; margin-top: 10px;">
            <button type="submit" class="btn-main"><i class="fas fa-plus"></i> Add Book to Library</button>
        </div>
    </form>
</div>

<div class="glass-card">
    <div class="card-header" style="justify-content: space-between;">
        <h2><i class="fas fa-list" style="color: #4f46e5;"></i> Book Catalog</h2>
        
        <form method="GET" style="display: flex; gap: 10px; flex: 0 0 400px;">
            <div class="input-wrapper" style="flex: 1;">
                <i class="fas fa-search"></i>
                <input type="search" name="search" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search_query); ?>" style="padding-top: 10px; padding-bottom: 10px;">
            </div>
            <button type="submit" class="btn-action btn-search-dark"><i class="fas fa-search"></i></button>
            <?php if (!empty($search_query)): ?>
                <a href="books.php" class="btn-action btn-del" style="display: flex; align-items: center;"><i class="fas fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-container">
        <table class="glass-table">
            <thead>
                <tr>
                    <th>Book ID (Base)</th>
                    <th>Cover</th>
                    <th>Title</th>
                    <th>Library</th>
                    <th>Type</th>
                    <th>Stock</th>
                    <th>Shelf</th>
                    <th>E-Book</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($books_result->num_rows > 0): ?>
                    <?php while ($book = $books_result->fetch_assoc()): 
                        // --- GET BASE UID FOR DISPLAY ---
                        $stmt_base_uid = $conn->prepare("SELECT book_uid FROM tbl_book_copies WHERE book_id = ? AND book_uid LIKE '%-BASE' LIMIT 1");
                        $stmt_base_uid->bind_param("i", $book['book_id']);
                        $stmt_base_uid->execute();
                        $base_row = $stmt_base_uid->get_result()->fetch_assoc();
                        $base_uid_display = $base_row ? substr($base_row['book_uid'], 0, -5) : "ID Error";
                        
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight: 700; font-family: monospace; color: #4f46e5;"><?php echo htmlspecialchars($base_uid_display); ?></div>
                            </td>
                            <td>
                                <?php if (!empty($book['cover_image'])): ?>
                                    <img src="<?php echo (strpos($book['cover_image'], 'http') === 0) ? htmlspecialchars($book['cover_image']) : '../' . htmlspecialchars($book['cover_image']); ?>" 
                                         alt="Cover" 
                                         style="height: 50px; width: 35px; object-fit: cover; border-radius: 4px; border: 1px solid #e5e7eb;">
                                <?php else: ?>
                                    <div style="height: 50px; width: 35px; background: #f3f4f6; border-radius: 4px; display: flex; align-items: center; justify-content: center; color: #cbd5e1; border: 1px solid #e5e7eb;">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($book['title']); ?></div>
                                <div style="font-size: 0.8rem; color: #6b7280;">ISBN: <?php echo htmlspecialchars($book['isbn'] ?: 'N/A'); ?></div>
                                <div style="font-size: 0.8rem; color: #4b5563;">By: <?php echo htmlspecialchars($book['author']); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($book['library_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span style="background: rgba(79, 70, 229, 0.1); color: #111827; padding: 3px 8px; border-radius: 4px; font-size: 0.85rem; font-weight: 500;"><?php echo htmlspecialchars($book['category']); ?></span>
                                <div style="font-size: 0.8rem; margin-top: 2px; color: #6b7280;"><?php echo htmlspecialchars($book['type_name'] ?? ''); ?></div>
                            </td>
                            <td>
                                <div>Total: <strong><?php echo $book['total_quantity']; ?></strong></div>
                                <div style="font-size: 0.85rem; color: #059669;">Avail: <?php echo $book['available_quantity']; ?></div>
                            </td>
                            <td><?php echo ($book['total_quantity'] > 0) ? htmlspecialchars($book['shelf_location']) : '<span style="color: #9ca3af;">N/A</span>'; ?></td>
                            <td>
                                <?php if (!empty($book['soft_copy_path'])): 
                                    $link = htmlspecialchars($book['soft_copy_path']);
                                    // Check if it's a remote link or local file
                                    $href = (strpos($link, 'http') === 0) ? $link : '../' . $link;
                                    $target = "_blank";
                                ?>
                                    <a href="<?php echo $href; ?>" target="<?php echo $target; ?>" class="btn-view"><i class="fas fa-eye"></i> Read</a>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 5px;">
                                    <button class="btn-action btn-edit edit-btn" 
                                        data-id="<?php echo $book['book_id']; ?>" 
                                        data-title="<?php echo htmlspecialchars($book['title']); ?>" 
                                        data-author="<?php echo htmlspecialchars($book['author']); ?>" 
                                        data-edition="<?php echo htmlspecialchars($book['edition'] ?? ''); ?>"
                                        data-publication="<?php echo htmlspecialchars($book['publication'] ?? ''); ?>"
                                        data-is-online="<?php echo $book['is_online_available']; ?>" 
                                        data-isbn="<?php echo htmlspecialchars($book['isbn']); ?>" 
                                        data-category="<?php echo htmlspecialchars($book['category']); ?>" 
                                        data-location="<?php echo htmlspecialchars($book['shelf_location']); ?>"
                                        data-quantity="<?php echo $book['total_quantity']; ?>"
                                        data-soft-copy-path="<?php echo htmlspecialchars($book['soft_copy_path']); ?>"
                                        data-content-type="<?php echo $book['content_type_id']; ?>"
                                        data-security="<?php echo $book['security_control']; ?>" 
                                        data-is-downloadable="<?php echo $book['is_downloadable']; ?>"
                                        data-price="<?php echo htmlspecialchars($book['price'] ?? '0.00'); ?>"
                                        data-cover-image="<?php echo htmlspecialchars($book['cover_image'] ?? ''); ?>"
                                        data-base-uid="<?php echo htmlspecialchars($base_uid_display); ?>"
                                        data-library-name="<?php echo htmlspecialchars($book['library_name'] ?? 'N/A'); ?>" >
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-action btn-del" onclick="openDeleteModal(<?php echo $book['book_id']; ?>, '<?php echo addslashes($book['title']); ?>', '<?php echo addslashes($book['author']); ?>', '<?php echo $base_uid_display; ?>')"><i class="fas fa-trash"></i></button>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="9" style="text-align: center; padding: 30px; color: #6b7280;">No books found matching your criteria.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleCoverMethod(method) {
        if (method === 'upload') {
            document.getElementById('coverUploadDiv').style.display = 'block';
            document.getElementById('coverUrlDiv').style.display = 'none';
        } else {
            document.getElementById('coverUploadDiv').style.display = 'none';
            document.getElementById('coverUrlDiv').style.display = 'block';
        }
    }

    function toggleEbookMethod(method) {
        const uploadDiv = document.getElementById('ebookUploadDiv');
        const urlDiv = document.getElementById('ebookUrlDiv');
        const fileInput = document.getElementById('fileInput');
        const urlInput = document.getElementById('soft_copy_url');

        if (method === 'upload') {
            uploadDiv.style.display = 'block';
            urlDiv.style.display = 'none';
            if(fileInput) fileInput.required = true;
            if(urlInput) urlInput.required = false;
        } else {
            uploadDiv.style.display = 'none';
            urlDiv.style.display = 'block';
            if(fileInput) fileInput.required = false;
            if(urlInput) urlInput.required = true;
        }
    }

    // --- NEW FUNCTIONS FOR EDIT MODAL COVER ---
    function toggleEditCoverMethod(method) {
        document.getElementById('editCoverUploadDiv').style.display = 'none';
        document.getElementById('editCoverUrlDiv').style.display = 'none';
        
        if (method === 'upload') {
            document.getElementById('editCoverUploadDiv').style.display = 'block';
        } else if (method === 'url') {
            document.getElementById('editCoverUrlDiv').style.display = 'block';
        }
    }

    function toggleEditEbookMethod(method) {
        document.getElementById('editEbookUploadDiv').style.display = 'none';
        document.getElementById('editEbookUrlDiv').style.display = 'none';
        
        if (method === 'upload') {
            document.getElementById('editEbookUploadDiv').style.display = 'block';
        } else if (method === 'url') {
            document.getElementById('editEbookUrlDiv').style.display = 'block';
        }
    }

    function previewEditCoverUrl() {
        const url = document.getElementById('edit_coverUrlInput').value;
        const previewDiv = document.getElementById('edit_urlPreview');
        const img = document.getElementById('edit_urlPreviewImg');
        
        if (url) {
            img.src = url;
            previewDiv.style.display = 'block';
        } else {
            previewDiv.style.display = 'none';
        }
    }
    // ------------------------------------------

    function openGoogleImages() {
        const title = document.querySelector('input[name="title"]').value;
        const author = document.querySelector('input[name="author"]').value;
        if (title) {
            const query = encodeURIComponent(title + ' ' + author + ' book cover');
            window.open('https://www.google.com/search?tbm=isch&q=' + query, '_blank');
        } else {
            alert('Please enter a book title first.');
        }
    }

    function previewCoverUrl() {
        const url = document.getElementById('coverUrlInput').value;
        const previewDiv = document.getElementById('urlPreview');
        const img = document.getElementById('urlPreviewImg');
        
        if (url) {
            img.src = url;
            previewDiv.style.display = 'block';
        } else {
            previewDiv.style.display = 'none';
        }
    }

    // Update Edit Modal Logic to populate cover fields
    document.addEventListener('DOMContentLoaded', function() {
        const editBtns = document.querySelectorAll('.edit-btn');
        editBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                const title = this.dataset.title;
                const author = this.dataset.author;
                const edition = this.dataset.edition;
                const publication = this.dataset.publication;
                const isbn = this.dataset.isbn;
                const category = this.dataset.category;
                const location = this.dataset.location;
                const quantity = parseInt(this.dataset.quantity);
                const isOnline = this.dataset.isOnline == 1;
                const softCopyPath = this.dataset.softCopyPath;
                const contentType = this.dataset.contentType;
                const security = this.dataset.security;
                const downloadable = this.dataset.isDownloadable;
                const price = this.dataset.price; 
                
                document.getElementById('edit_book_id').value = id;
                document.getElementById('edit_title').value = title;
                document.getElementById('edit_author').value = author;
                document.getElementById('edit_edition').value = edition;
                document.getElementById('edit_publication').value = publication;
                document.getElementById('edit_isbn').value = isbn;
                document.getElementById('edit_category').value = category;
                document.getElementById('edit_location').value = location;
                document.getElementById('edit_quantity').value = quantity;
                document.getElementById('edit_contentType').value = contentType;
                document.getElementById('edit_security').value = security;
                document.getElementById('edit_is_downloadable').value = downloadable;
                document.getElementById('edit_price').value = price; 
                
                // Populate Read-Only Fields
                document.getElementById('edit_display_uid').textContent = this.dataset.baseUid;
                document.getElementById('edit_display_library').textContent = this.dataset.libraryName;
                
                // Determine book type for setting the dropdown
                let determinedType = 'Physical';
                if(isOnline && quantity === 0) {
                    determinedType = 'E-Book';
                } else if (isOnline && quantity > 0) {
                    determinedType = 'Both';
                }
                document.getElementById('edit_bookType').value = determinedType;

                // Manually trigger the field toggling to show/hide E-Book sections
                toggleEditFields();

                // --- E-BOOK SOURCE LOGIC ---
                // Reset E-Book Source to 'Keep Current'
                document.getElementById('edit_ebook_method_keep').click(); 
                
                // Display current file info
                const currentFilePath = document.getElementById('currentFilePath');
                if (softCopyPath) {
                    if (softCopyPath.startsWith('http://') || softCopyPath.startsWith('https://')) {
                        // It is a URL
                        currentFilePath.textContent = softCopyPath;
                        currentFilePath.style.color = '#3b82f6';
                        
                        // Populate URL input just in case they want to switch to URL method immediately
                        document.getElementById('edit_ebookUrlInput').value = softCopyPath;
                    } else {
                        // It is a file path
                        currentFilePath.textContent = softCopyPath.split('/').pop();
                        currentFilePath.style.color = '#111827';
                    }
                } else {
                    currentFilePath.textContent = 'None';
                    currentFilePath.style.color = '#9ca3af';
                }

                // --- COVER IMAGE LOGIC ---
                // Populate Cover Image
                const coverImage = this.getAttribute('data-cover-image');
                const currentImg = document.getElementById('edit_current_cover_img');
                const noCoverMsg = document.getElementById('edit_no_cover_msg');
                
                // Reset to "Keep Current" state
                document.getElementById('edit_method_keep').click(); 
                document.getElementById('edit_coverUrlInput').value = '';
                document.getElementById('edit_urlPreview').style.display = 'none';

                if (coverImage) {
                    // Check if external URL or internal path
                    const src = coverImage.startsWith('http') ? coverImage : '../' + coverImage;
                    currentImg.src = src;
                    currentImg.style.display = 'block';
                    noCoverMsg.style.display = 'none';
                } else {
                    currentImg.style.display = 'none';
                    noCoverMsg.style.display = 'flex';
                }

                document.getElementById('editModal').style.display = 'flex';
            });
        });
    });
</script>

<div id="deleteConfirmModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center;">
        <div style="margin-bottom: 20px;">
            <i class="fas fa-exclamation-circle" style="font-size: 3rem; color: #b91c1c;"></i>
        </div>
        <h2 style="color: #111827; margin-bottom: 10px;">Confirm Deletion</h2>
        <p style="color: #4b5563; margin-bottom: 5px;">Are you sure you want to delete this book?</p>
        <div style="background: #f3f4f6; padding: 15px; border-radius: 10px; margin-bottom: 20px; text-align: left;">
            <div style="font-weight: 700; color: #111827; margin-bottom: 5px;" id="del_book_title"></div>
            <div style="font-size: 0.9rem; color: #4b5563; margin-bottom: 5px;" id="del_book_author"></div>
            <div style="font-size: 0.8rem; color: #6b7280; font-family: monospace;" id="del_book_uid"></div>
        </div>
        <p style="color: #b91c1c; font-size: 0.9rem; margin-bottom: 25px; font-weight: 600;">This action will remove all copies and cannot be undone.</p>
        
        <form method="POST" id="deleteBookForm">
            <input type="hidden" name="action" value="delete_book">
            <input type="hidden" id="delete_book_id" name="book_id">
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn-action" style="background: #e5e7eb; color: #374151; padding: 10px 20px;" onclick="document.getElementById('deleteConfirmModal').style.display='none'">Cancel</button>
                <button type="submit" class="btn-action btn-del" style="padding: 10px 20px;">Yes, Delete</button>
            </div>
        </form>
    </div>
</div>

<div id="addContentTypeModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close-btn" onclick="document.getElementById('addContentTypeModal').style.display='none'" style="float: right; font-size: 1.5rem; font-weight: bold; cursor: pointer; color: #aaa;">&times;</span>
        <h2 style="margin-top: 0; color: #000; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Add Content Type</h2>
        
        <form method="POST">
            <input type="hidden" name="action" value="add_content_type">
            <div class="form-group">
                <label>New Content Type Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-tag"></i>
                    <input type="text" name="new_type_name" class="form-control" required placeholder="e.g. Thesis, Journal">
                </div>
            </div>
            <div style="margin-top: 20px; text-align: right;">
                <button type="submit" class="btn-main" style="width: auto;">Add Type</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close-btn" onclick="document.getElementById('editModal').style.display='none'">&times;</span>
        <h2 style="margin-top: 0; color: #000; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">Edit Book Details</h2>
        
        <form method="POST" enctype="multipart/form-data" class="form-grid">
            <input type="hidden" name="action" value="edit_book">
            <input type="hidden" id="edit_book_id" name="book_id">
            
            <div style="display: flex; gap: 15px; margin-bottom: 15px; background: #f9fafb; padding: 10px; border-radius: 8px; border: 1px solid #e5e7eb;">
                <div style="flex: 1;">
                    <label style="font-size: 0.75rem; color: #6b7280; display: block; margin-bottom: 2px;">Base UID</label>
                    <div id="edit_display_uid" style="font-family: monospace; font-weight: 600; color: #4f46e5;"></div>
                </div>
                <div style="flex: 1;">
                    <label style="font-size: 0.75rem; color: #6b7280; display: block; margin-bottom: 2px;">Library</label>
                    <div id="edit_display_library" style="font-weight: 600; color: #111827;"></div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Title</label>
                <div class="input-wrapper">
                    <i class="fas fa-heading"></i>
                    <input type="text" id="edit_title" name="title" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label>Author</label>
                <div class="input-wrapper">
                    <i class="fas fa-user-pen"></i>
                    <input type="text" id="edit_author" name="author" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label>Edition</label>
                <div class="input-wrapper">
                    <i class="fas fa-bookmark"></i>
                    <input type="text" id="edit_edition" name="edition" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>Publication</label>
                <div class="input-wrapper">
                    <i class="fas fa-building"></i>
                    <input type="text" id="edit_publication" name="publication" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>ISBN</label>
                <div class="input-wrapper">
                    <i class="fas fa-barcode"></i>
                    <input type="text" id="edit_isbn" name="isbn" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>Category</label>
                <div class="input-wrapper">
                    <i class="fas fa-tag"></i>
                    <input type="text" id="edit_category" name="category" class="form-control" required>
                </div>
            </div>

            <div class="form-group">
                <label>Price</label>
                <div class="input-wrapper">
                    <i class="fas fa-indian-rupee-sign"></i>
                    <input type="number" step="0.01" id="edit_price" name="price" class="form-control" required>
                </div>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label>Update Cover Image</label>
                <div style="background: #f9fafb; padding: 15px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    
                    <div style="display: flex; align-items: flex-start; gap: 20px;">
                        <div style="flex-shrink: 0; width: 80px; text-align: center;">
                            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 5px;">Current</div>
                            <img id="edit_current_cover_img" src="" alt="None" style="height: 100px; width: 70px; object-fit: cover; border-radius: 5px; border: 1px solid #ddd; display: none;">
                            <div id="edit_no_cover_msg" style="height: 100px; width: 70px; background: #e5e7eb; border-radius: 5px; display: flex; align-items: center; justify-content: center; color: #9ca3af; font-size: 1.5rem; display: none;">
                                <i class="fas fa-image"></i>
                            </div>
                        </div>

                        <div style="flex-grow: 1;">
                            <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                                    <input type="radio" name="cover_method" value="keep" id="edit_method_keep" checked onclick="toggleEditCoverMethod('keep')"> 
                                    Keep Current
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                                    <input type="radio" name="cover_method" value="upload" id="edit_method_upload" onclick="toggleEditCoverMethod('upload')"> 
                                    Upload New
                                </label>
                                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                                    <input type="radio" name="cover_method" value="url" id="edit_method_url" onclick="toggleEditCoverMethod('url')"> 
                                    URL
                                </label>
                            </div>

                            <div id="editCoverUploadDiv" style="display: none;">
                                <input type="file" name="cover_image_file" class="form-control" accept="image/*">
                            </div>

                            <div id="editCoverUrlDiv" style="display: none;">
                                <div class="input-wrapper">
                                    <i class="fas fa-link"></i>
                                    <input type="url" id="edit_coverUrlInput" name="cover_image_url" class="form-control" placeholder="Paste image link here..." oninput="previewEditCoverUrl()">
                                </div>
                                <div id="edit_urlPreview" style="margin-top: 10px; display: none;">
                                    <img id="edit_urlPreviewImg" src="" alt="Preview" style="height: 80px; border-radius: 4px; border: 1px solid #ddd;">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Content Type</label>
                <div class="input-wrapper">
                    <i class="fas fa-file-alt"></i>
                    <select id="edit_contentType" name="content_type_id" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach($content_types as $ct): ?>
                            <option value="<?php echo $ct['content_type_id']; ?>"><?php echo htmlspecialchars($ct['type_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group" id="edit_shelfLocationGroup">
                <label>Shelf Location</label>
                <div class="input-wrapper">
                    <i class="fas fa-map-marker-alt"></i>
                    <input type="text" id="edit_location" name="shelf_location" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label>Book Type</label>
                <div class="input-wrapper">
                    <i class="fas fa-list-alt"></i>
                    <select id="edit_bookType" name="book_type" class="form-control" required style="padding-left: 40px; width: 100%;" onchange="toggleEditFields()">
                        <option value="Physical">Physical Only</option>
                        <option value="E-Book">E-Book Only</option>
                        <option value="Both">Physical + E-Book</option>
                    </select>
                </div>
            </div>

            <div id="edit_securityGroup" class="form-group" style="display: none;">
                <label>Security Control</label>
                <div class="input-wrapper">
                    <i class="fas fa-shield-alt"></i>
                    <select id="edit_security" name="security_control" class="form-control" onchange="toggleEditFields()">
                        <option value="No">No</option>
                        <option value="Yes">Yes</option>
                    </select>
                </div>
            </div>

            <div id="edit_downloadGroup" class="form-group" style="display: none;">
                <label>Allow Download?</label>
                <div class="input-wrapper">
                    <i class="fas fa-download"></i>
                    <select id="edit_is_downloadable" name="is_downloadable" class="form-control">
                        <option value="0">No</option>
                        <option value="1">Yes</option>
                    </select>
                </div>
            </div>

            <div id="edit_physicalGroup" class="form-group">
                <label>Physical Copies (Total)</label>
                <div class="input-wrapper">
                    <i class="fas fa-copy"></i>
                    <input type="number" id="edit_quantity" name="quantity" class="form-control" required min="0">
                </div>
            </div>

            <div id="edit_ebookGroup" class="form-group" style="display: none; grid-column: 1 / -1;">
                <label>Update E-Book</label>
                <div style="background: #f9fafb; padding: 15px; border-radius: 10px; border: 1px solid #e5e7eb;">
                    
                    <p style="margin: 0 0 10px 0; font-size: 0.9rem;">
                        Current File/Link: <strong id="currentFilePath">N/A</strong>
                    </p>

                    <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                            <input type="radio" name="ebook_method" value="keep" id="edit_ebook_method_keep" checked onclick="toggleEditEbookMethod('keep')"> 
                            Keep Current
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                            <input type="radio" name="ebook_method" value="upload" onclick="toggleEditEbookMethod('upload')"> 
                            Upload New
                        </label>
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem;">
                            <input type="radio" name="ebook_method" value="url" onclick="toggleEditEbookMethod('url')"> 
                            New URL
                        </label>
                    </div>

                    <div id="editEbookUploadDiv" style="display: none;">
                        <div class="file-upload-box" id="editUploadBox" style="padding: 15px;">
                            <input type="file" id="editFileInput" name="soft_copy_file" accept=".pdf,.epub" style="display: none;">
                            <div id="editUploadMessage">
                                <i class="fas fa-cloud-upload-alt" style="font-size: 1.5rem; color: #4b5563;"></i>
                                <div style="margin-top: 5px; font-size: 0.9rem; color: #111827;">Click to upload file</div>
                            </div>
                            <div id="editFilePreview" style="display: none; padding: 10px;">
                                <i class="fas fa-file-pdf" style="font-size: 2rem; color: #b91c1c;"></i>
                                <div id="editFileName" style="font-size: 0.8rem; color: #111827; margin-top: 5px; font-weight: 600; overflow: hidden; text-overflow: ellipsis; max-width: 100%;"></div>
                            </div>
                        </div>
                    </div>

                    <div id="editEbookUrlDiv" style="display: none;">
                        <div class="input-wrapper">
                            <i class="fas fa-link"></i>
                            <input type="url" id="edit_ebookUrlInput" name="soft_copy_url" class="form-control" placeholder="https://example.com/book.pdf">
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="grid-column: 1 / -1; margin-top: 15px;">
                <button type="submit" class="btn-main">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// --- ADD BOOK LOGIC ---
document.addEventListener('DOMContentLoaded', function() {

    const bookType = document.getElementById('bookType');
    const physicalGroup = document.getElementById('physicalGroup');
    const physicalQuantity = document.getElementById('physicalQuantity');
    const ebookGroup = document.getElementById('ebookGroup');
    const securityGroup = document.getElementById('securityGroup');
    const shelfLocationGroup = document.getElementById('shelfLocationGroup');
    const shelfLocationInput = document.getElementById('shelf_location');
    const fileInput = document.getElementById('fileInput');
    const uploadBox = document.getElementById('uploadBox');
    const uploadMessage = document.getElementById('uploadMessage');
    const filePreview = document.getElementById('filePreview');
    const fileNameDisplay = document.getElementById('fileName');
    
    // New Elements
    const securitySelect = document.getElementById('security_control');
    const downloadGroup = document.getElementById('downloadGroup');

    function toggleFields() {
        const type = bookType.value;
        const securityVal = securitySelect.value;

        // Reset required status and display
        physicalGroup.style.display = 'none';
        ebookGroup.style.display = 'none';
        securityGroup.style.display = 'none';
        shelfLocationGroup.style.display = 'none';
        downloadGroup.style.display = 'none';
        
        physicalQuantity.required = false;
        shelfLocationInput.required = false;
        physicalQuantity.min = '1';
        
        // Reset file preview on type change if the field is hidden
        if(fileInput) fileInput.value = '';
        if(uploadMessage) uploadMessage.style.display = 'block';
        if(filePreview) filePreview.style.display = 'none';


        if (type === 'Physical') {
            physicalGroup.style.display = 'block';
            shelfLocationGroup.style.display = 'block';
            physicalQuantity.required = true;
            shelfLocationInput.required = true;
        } else if (type === 'E-Book') {
            ebookGroup.style.display = 'block';
            securityGroup.style.display = 'block';
            physicalQuantity.min = '0'; 
            
            // Show Download option only if security is No
            if(securityVal === 'No') {
                downloadGroup.style.display = 'block';
            }
        } else if (type === 'Both') {
            physicalGroup.style.display = 'block';
            ebookGroup.style.display = 'block';
            securityGroup.style.display = 'block';
            shelfLocationGroup.style.display = 'block';
            physicalQuantity.required = true;
            shelfLocationInput.required = true;
            
            // Show Download option only if security is No
            if(securityVal === 'No') {
                downloadGroup.style.display = 'block';
            }
        }
        
        // Re-run ebook method toggle to ensure correct required attributes
        const selectedMethod = document.querySelector('input[name="ebook_method"]:checked');
        if(selectedMethod && (type === 'E-Book' || type === 'Both')) {
            toggleEbookMethod(selectedMethod.value);
        }
    }

    // File Preview Logic (Add Book)
    if(fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                uploadMessage.style.display = 'none';
                filePreview.style.display = 'flex';
                fileNameDisplay.textContent = this.files[0].name;
            } else {
                uploadMessage.style.display = 'block';
                filePreview.style.display = 'none';
            }
        });
    }

    // Handle initial state and changes
    bookType.addEventListener('change', toggleFields);
    // Use 'change' listener for security dropdown as well
    securitySelect.addEventListener('change', toggleFields);
    
    toggleFields();
    
    // File input click handler
    if(uploadBox) {
        uploadBox.addEventListener('click', function(e) {
            if(e.target.id !== 'fileInput') {
                 fileInput.click();
            }
        });
    }

    // --- EDIT MODAL LOGIC ---
    window.toggleEditFields = function() {
        const type = document.getElementById('edit_bookType').value;
        const editPhysicalGroup = document.getElementById('edit_physicalGroup');
        const editEbookGroup = document.getElementById('edit_ebookGroup');
        const editSecurityGroup = document.getElementById('edit_securityGroup');
        const editShelfLocationGroup = document.getElementById('edit_shelfLocationGroup');
        const editQuantity = document.getElementById('edit_quantity');
        const editLocationInput = document.getElementById('edit_location');
        
        // New Elements
        const editSecuritySelect = document.getElementById('edit_security');
        const editDownloadGroup = document.getElementById('edit_downloadGroup');
        
        editPhysicalGroup.style.display = 'none';
        editEbookGroup.style.display = 'none';
        editSecurityGroup.style.display = 'none';
        editShelfLocationGroup.style.display = 'none';
        editDownloadGroup.style.display = 'none';
        
        editQuantity.required = false;
        editLocationInput.required = false;

        const securityVal = editSecuritySelect.value;

        if (type === 'Physical') {
            editPhysicalGroup.style.display = 'block';
            editShelfLocationGroup.style.display = 'block';
            editQuantity.required = true;
            editLocationInput.required = true;
        } else if (type === 'E-Book') {
            editEbookGroup.style.display = 'block';
            editSecurityGroup.style.display = 'block';
            
            if(securityVal === 'No') {
                editDownloadGroup.style.display = 'block';
            }
        } else if (type === 'Both') {
            editPhysicalGroup.style.display = 'block';
            editEbookGroup.style.display = 'block';
            editSecurityGroup.style.display = 'block';
            editShelfLocationGroup.style.display = 'block';
            editQuantity.required = true;
            editLocationInput.required = true;
            
            if(securityVal === 'No') {
                editDownloadGroup.style.display = 'block';
            }
        }
    }

    const editFileInput = document.getElementById('editFileInput');
    if(editFileInput) {
        editFileInput.addEventListener('change', function() {
            const currentFilePath = document.getElementById('currentFilePath');
            if (this.files.length > 0) {
                // currentFilePath.textContent = 'New file selected';
                // currentFilePath.style.color = '#059669'; 
                document.getElementById('editUploadMessage').style.color = '#111827';
                
                // Show preview
                document.getElementById('editUploadMessage').style.display = 'none';
                document.getElementById('editFilePreview').style.display = 'flex';
                document.getElementById('editFileName').textContent = this.files[0].name;
            } else {
                // Restore original text appearance
                document.getElementById('editUploadMessage').style.display = 'block';
                document.getElementById('editFilePreview').style.display = 'none';
            }
        });
    }
    
    // File input click handler for Edit Modal
    const editUploadBox = document.getElementById('editUploadBox');
    if(editUploadBox) {
        editUploadBox.addEventListener('click', function(e) {
            if(e.target.id !== 'editFileInput') {
                 editFileInput.click();
            }
        });
    }
    
    window.onclick = function(event) {
        if (event.target == document.getElementById('editModal')) {
            document.getElementById('editModal').style.display = 'none';
        }
        if (event.target == document.getElementById('addContentTypeModal')) {
            document.getElementById('addContentTypeModal').style.display = 'none';
        }
        if (event.target == document.getElementById('deleteConfirmModal')) {
            document.getElementById('deleteConfirmModal').style.display = 'none';
        }
    }

    // Delete Modal Trigger
    window.openDeleteModal = function(id, title, author, uid) {
        document.getElementById('delete_book_id').value = id;
        document.getElementById('del_book_title').textContent = title;
        document.getElementById('del_book_author').textContent = 'By: ' + author;
        document.getElementById('del_book_uid').textContent = 'ID: ' + uid;
        document.getElementById('deleteConfirmModal').style.display = 'flex';
    }
});
</script>

<?php
admin_footer();
close_db_connection($conn);
?>