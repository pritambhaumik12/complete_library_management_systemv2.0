<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_id = $_SESSION['admin_id'];
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic Validation
    if (empty($password) || empty($confirm_password)) {
        $_SESSION['flash_error'] = "Password fields cannot be empty.";
        echo "<script>window.history.back();</script>";
        exit;
    }

    if ($password !== $confirm_password) {
        $_SESSION['flash_error'] = "Passwords do not match.";
        echo "<script>window.history.back();</script>";
        exit;
    }

    if (strlen($password) < 6) {
        $_SESSION['flash_error'] = "Password must be at least 6 characters long.";
        echo "<script>window.history.back();</script>";
        exit;
    }

    // Update Password (Plain text as per your existing system, though hashing is recommended)
    $stmt = $conn->prepare("UPDATE tbl_admin SET password = ? WHERE admin_id = ?");
    $stmt->bind_param("si", $password, $admin_id);

    if ($stmt->execute()) {
        $_SESSION['flash_success'] = "Password updated successfully.";
    } else {
        $_SESSION['flash_error'] = "Database error: " . $conn->error;
    }
    
    // Redirect back to the page they came from
    if(isset($_SERVER['HTTP_REFERER'])) {
        header("Location: " . $_SERVER['HTTP_REFERER']);
    } else {
        redirect('index.php');
    }
}
?>