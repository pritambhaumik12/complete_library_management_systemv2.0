<?php
// Database connection details for XAMPP
// Database name: lms_db
// User: root (default XAMPP user)
// Password: (empty by default in XAMPP)

$servername = "localhost";
$username = "root";
$password = ""; // Plain text password as per XAMPP default
$dbname = "lms55_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Function to safely close the connection (optional, but good practice)
function close_db_connection($conn) {
    $conn->close();
}
?>
