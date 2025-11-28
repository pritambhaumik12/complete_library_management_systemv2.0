<?php
require_once 'includes/functions.php';
require_member_login();
global $conn;

header('Content-Type: application/json');

$member_id = $_SESSION['member_id'];
$action = $_POST['action'] ?? '';
$book_id = (int)($_POST['book_id'] ?? 0);

if ($book_id === 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Book ID.']);
    exit;
}

if ($action === 'add') {
    // Check if already exists to prevent duplicate entry error
    $stmt_check = $conn->prepare("SELECT favorite_id FROM tbl_favorites WHERE member_id = ? AND book_id = ?");
    $stmt_check->bind_param("ii", $member_id, $book_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows > 0) {
        echo json_encode(['success' => true, 'message' => 'Book already in favorites.']);
        exit;
    }

    // Add to favorites
    $stmt = $conn->prepare("INSERT INTO tbl_favorites (member_id, book_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $member_id, $book_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Book added to favorites.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add to favorites: ' . $conn->error]);
    }

} elseif ($action === 'remove') {
    // Remove from favorites
    $stmt = $conn->prepare("DELETE FROM tbl_favorites WHERE member_id = ? AND book_id = ?");
    $stmt->bind_param("ii", $member_id, $book_id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Book removed from favorites.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to remove from favorites: ' . $conn->error]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action.']);
}

close_db_connection($conn);
?>
