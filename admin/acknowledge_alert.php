<?php
require_once '../includes/functions.php';
require_admin_login();

header('Content-Type: application/json');

// 1. Fetch the timestamp of the *absolute latest* system alert
global $conn;
// Ensure we are fetching the latest timestamp across ALL alerts, not just the last day.
$result = $conn->query("SELECT UNIX_TIMESTAMP(created_at) AS latest_timestamp FROM tbl_system_alerts ORDER BY created_at DESC LIMIT 1");
$latest_alert_time = (int)($result->fetch_assoc()['latest_timestamp'] ?? time());
close_db_connection($conn);


// 2. Update the session timestamp to the latest alert time found (or current time if no alerts exist)
// This marks everything up to the latest alert as "seen".
$_SESSION['last_alert_view_timestamp'] = $latest_alert_time;

echo json_encode(['success' => true, 'timestamp' => $latest_alert_time]);
?>