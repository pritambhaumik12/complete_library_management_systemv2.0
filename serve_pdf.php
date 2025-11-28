<?php
// File: lms_project_2/serve_pdf.php

require_once 'includes/functions.php';

// 1. Security Check: Ensure user is logged in
// If they aren't logged in, this function redirects them to login.php immediately.
require_member_login(); 

global $conn;

// 2. Validation: Check if a book ID was provided
if (!isset($_GET['id'])) {
    die("No book specified.");
}

$book_id = (int)$_GET['id'];

// 3. Fetch the file path from the database securely
$stmt = $conn->prepare("SELECT soft_copy_path, title FROM tbl_books WHERE book_id = ?");
$stmt->bind_param("i", $book_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Book not found.");
}

$book = $result->fetch_assoc();
$relative_path = $book['soft_copy_path']; // e.g., "uploads/books/abc12345.pdf"

// Security: Prevent directory traversal attacks (e.g., ../../system/passwd)
// We ensure the path actually points inside our uploads directory.
$base_dir = __DIR__ . '/'; // The root folder of your project
$full_path = realpath($base_dir . $relative_path);

// Verify the file exists and is actually inside the uploads/books folder
if ($full_path && file_exists($full_path) && strpos($full_path, realpath($base_dir . 'uploads/books/')) === 0) {
    
    // 4. Serve the file
    // These headers tell the browser "This is a PDF, treat it like one."
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . basename($full_path) . '"');
    header('Content-Length: ' . filesize($full_path));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    // Output the file content to the browser
    readfile($full_path);
    exit;
} else {
    // File doesn't exist or is outside the allowed folder
    header("HTTP/1.0 404 Not Found");
    die("File not found or access denied.");
}
?>