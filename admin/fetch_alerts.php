<?php
require_once '../includes/functions.php';
require_admin_login();
global $conn;

header('Content-Type: application/json');

// Fetch the timestamp of the *absolute latest* system alert for comparison
$result = $conn->query("SELECT UNIX_TIMESTAMP(created_at) AS latest_timestamp FROM tbl_system_alerts ORDER BY created_at DESC LIMIT 1");
$row = $result->fetch_assoc();

$response = [
    'latest_timestamp' => (int)($row['latest_timestamp'] ?? 0)
];

echo json_encode($response);

close_db_connection($conn);
?>